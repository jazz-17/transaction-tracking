# Deployment Guide

This app is deployed to the **same VPS as `reservation-system`** and **shares that
stack's PostgreSQL instance** (a dedicated database + role, reached over a shared
Docker network). It is served on **https://tally.spode.dev** through its **own**
Cloudflare Tunnel. There are no public web ports.

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
- **No `postgres` / `redis` containers here** — queue/cache/session use the `database`
  driver against the shared PostgreSQL. **No volumes** — all state lives in Postgres,
  logs go to `stderr`.

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

Run these once on the host. They (a) create the shared network, (b) attach the
reservation stack's Postgres to it, and (c) create this app's database + role.

```bash
# 1. Create the shared docker network (idempotent)
docker network create shared-pg || true

# 2. Redeploy the reservation stack so its `postgres` joins shared-pg
#    (alias: shared-postgres). This RECREATES the postgres container — brief
#    reservation-app DB blip; data is safe in the named volume.
cd /srv/reservation-system
git pull
docker compose --env-file .env.production -f docker-compose.prod.yml up -d

# 3. Create the dedicated database + least-privilege role.
#    `init.sql` only runs on an empty data dir, so this is manual.
docker compose --env-file .env.production -f docker-compose.prod.yml exec postgres \
  psql -U postgres -c "CREATE ROLE transaction_tracking WITH LOGIN PASSWORD 'STRONG_DB_PASSWORD';"
docker compose --env-file .env.production -f docker-compose.prod.yml exec postgres \
  psql -U postgres -c "CREATE DATABASE transaction_tracking OWNER transaction_tracking;"

# 4. PostgreSQL 15+: grant the role rights on its database's public schema,
#    otherwise `migrate` fails with "permission denied for schema public".
docker compose --env-file .env.production -f docker-compose.prod.yml exec postgres \
  psql -U postgres -d transaction_tracking -c "GRANT ALL ON SCHEMA public TO transaction_tracking;"
```

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

> The PostgreSQL volume belongs to the **reservation-system** stack. Destructive DB
> operations (`down -v`) must be run there, and would wipe **both** apps' databases.

### Connecting to Production DB (via SSH tunnel)

The shared Postgres is published only on the host loopback (`127.0.0.1:5432`). Use an SSH tunnel:

```bash
ssh -L 5432:localhost:5432 user@your-vps-ip
```

Then connect your client to `localhost:5432`, database `transaction_tracking`, with the
`transaction_tracking` role credentials.
