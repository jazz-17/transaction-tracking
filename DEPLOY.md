# Deployment Guide

This app is deployed to the **same VPS as `reservation-system`** and **shares a
PostgreSQL instance** with it (a dedicated database + role, reached over a shared
Docker network). That Postgres is **not** part of either app stack — it lives in its
own `/srv/infra` stack so its lifecycle is independent (bringing an app down, even
`down -v`, never touches it). The app is served on **https://tally.spode.dev** through
its **own** Cloudflare Tunnel. There are no public web ports.

## Local Development

Local dev uses SQLite and needs no containers:

```bash
composer run dev
```

- App: http://localhost:8000

## Production (shared VPS)

### Architecture

- `app` — PHP-FPM (serves the application).
- `nginx` — static files + FastCGI to `app`. Reached only by `cloudflared`.
- `queue` — `php artisan queue:work` (e.g. password-reset and future emails).
- `cloudflared` — dedicated Cloudflare Tunnel for `tally.spode.dev` → `http://nginx:80`.
- **No `postgres` / `redis` containers in this stack** — queue/cache/session use the
  `database` driver against the shared Postgres in `/srv/infra` (reached over the
  `shared-pg` network at the alias `shared-postgres`). **No volumes** — all state lives
  in Postgres, logs go to `stderr`.

### Networking (Cloudflare Tunnel)

TLS terminates at the Cloudflare edge; `cloudflared` dials out and forwards plain HTTP
to `nginx`. The host firewall is already handled by the reservation-system setup (no
inbound ports; outbound UDP 7844 is all `cloudflared` needs).

1. In the Cloudflare dashboard (Networking → Tunnels) create a **new** tunnel for this
   app, add the public hostname `tally.spode.dev → http://nginx:80`, and the proxied
   CNAME to `<TUNNEL_ID>.cfargotunnel.com`.
2. Put the connector token in `.env.production` as `CLOUDFLARE_TUNNEL_TOKEN=...`.

---

### One-time: shared PostgreSQL

The shared Postgres cluster (`/srv/infra` stack, container `infra-postgres-1`) and the
`shared-pg` network are created/owned by that stack — see `/srv/infra/docker-compose.yml`.
If they don't exist yet, bring them up first:

```bash
docker network create shared-pg || true   # idempotent; usually already exists
cd /srv/infra && docker compose up -d      # starts infra-postgres-1 on shared-pg
```

This app's database + role are recorded in `/srv/infra/databases.sql` and applied once.
The `POSTGRES_*` env vars only seed an empty volume, so per-app databases are manual:

```bash
# /srv/infra/databases.sql contains:
#   CREATE ROLE transaction_tracking WITH LOGIN PASSWORD 'STRONG_DB_PASSWORD';
#   CREATE DATABASE transaction_tracking OWNER transaction_tracking;
docker exec -i infra-postgres-1 psql -U postgres < /srv/infra/databases.sql
```

No `GRANT ... ON SCHEMA public` is needed: the role **owns** its database, so on
PostgreSQL 15+ it is implicitly a member of `pg_database_owner` and already holds
`USAGE`+`CREATE` on the `public` schema. (`STRONG_DB_PASSWORD` must match `DB_PASSWORD`
in `.env.production`.)

### First-time Setup

```bash
# 1. Clone the repo
sudo mkdir -p /srv
sudo chown -R "$USER":"$USER" /srv
git clone https://github.com/your-user/transaction-tracking.git /srv/transaction-tracking
cd /srv/transaction-tracking

# 2. Create .env.production from the committed template (.env.production is gitignored;
#    copy it from your machine or recreate it on the box). Key values to set:
#      APP_KEY=                  (generate locally: php artisan key:generate --show)
#      DB_PASSWORD=              (the STRONG_DB_PASSWORD from the step above)
#      CLOUDFLARE_TUNNEL_TOKEN=  (connector token from the Cloudflare dashboard)
#    Mail stays MAIL_MAILER=log until SES SMTP credentials are issued.

# 3. Build and start.
#    --env-file is required so Compose can read CLOUDFLARE_TUNNEL_TOKEN for interpolation.
docker compose --env-file .env.production -f docker-compose.prod.yml up -d --build

# 4. Run migrations (no seeding — the seeder only creates a throwaway test user).
docker compose --env-file .env.production -f docker-compose.prod.yml exec app php artisan migrate --force

# 5. Create the initial user (registration is disabled by design).
docker compose --env-file .env.production -f docker-compose.prod.yml exec app \
  php artisan tinker --execute '\App\Models\User::create(["name" => "Admin", "email" => "you@example.com", "password" => bcrypt("change-this-password")]);'
```

### Deploying Changes

```bash
cd /srv/transaction-tracking
git pull
docker compose --env-file .env.production -f docker-compose.prod.yml up -d --build
docker compose --env-file .env.production -f docker-compose.prod.yml exec app php artisan migrate --force

# Reload PHP-FPM to clear OPcache after a rebuild (bytecode is frozen at build time)
docker compose --env-file .env.production -f docker-compose.prod.yml exec app kill -USR2 1
```

### Enabling Amazon SES (when ready)

1. Verify the sending domain/identity in SES and request production access (leave the sandbox).
2. Create SES **SMTP credentials**.
3. In `.env.production`, switch `MAIL_MAILER=log` → `smtp` and fill `MAIL_SCHEME=tls`,
   `MAIL_HOST=email-smtp.<region>.amazonaws.com`, `MAIL_PORT=587`, `MAIL_USERNAME`, `MAIL_PASSWORD`.
4. `up -d` to recreate `app` + `queue`, then send a test (e.g. trigger a password reset).

### Useful Commands

```bash
# View logs (logs are on stderr -> docker)
docker compose --env-file .env.production -f docker-compose.prod.yml logs -f app
docker compose --env-file .env.production -f docker-compose.prod.yml logs -f queue

# Restart / stop
docker compose --env-file .env.production -f docker-compose.prod.yml restart
docker compose --env-file .env.production -f docker-compose.prod.yml down

# Run artisan / open a shell
docker compose --env-file .env.production -f docker-compose.prod.yml exec app php artisan <command>
docker compose --env-file .env.production -f docker-compose.prod.yml exec app sh
```

> The PostgreSQL data lives in the external volume `shared_postgres_data`, owned by the
> **`/srv/infra`** stack and shared by every app's database. It is `external`, so a stray
> `docker compose down -v` in this app stack can't touch it. Destructive operations must
> be run deliberately against `/srv/infra` and would wipe **all** apps' databases.

### Connecting to Production DB (via SSH tunnel)

The shared Postgres is published only on the host loopback (`127.0.0.1:5432`). Use an SSH tunnel:

```bash
ssh -L 5432:localhost:5432 user@your-vps-ip
```

Then connect your client to `localhost:5432`, database `transaction_tracking`, with the
`transaction_tracking` role credentials.
