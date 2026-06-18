# Transaction Tracking — Reference

A personal finance tracker. Log in, record what you spent (amount, card, category),
fast. Shared with family (each person gets a private ledger), robust enough to trust
with real money.

This document is the **single source of truth** for the design decisions, the data
model, the invariants, and the phased build. Update it when a decision changes.

---

## 1. Goals

- **Fast data entry** — recording an expense is near-frictionless (amount, card, category, done).
- **Always correct & auditable** — the books balance by construction; balances can never silently drift.
- **Multi-currency from day one** — first-class, not a retrofit.
- **Per-person, private** — each user has an isolated ledger.
- **Robust auth & multi-user** — the reason for Laravel.

---

## 2. Core model in one paragraph

Double-entry. **Both cards/wallets and categories are `Account`s**, distinguished by
`type`. A `Transaction` is a header; its `Posting`s are the balanced lines. Money always
moves *from* one account *to* another, so it can't be created or destroyed. The UI feels
single-entry ("3 fields") while the database stores rigorous double-entry. Money is stored
as **signed integer minor units**, never floats.

---

## 3. Locked decisions

These were resolved during design review. Each is load-bearing — changing one ripples.

| # | Decision | Rationale |
|---|---|---|
| 1 | **Single `RecordTransaction` service is the sole write path.** Invariant enforced there **and** by a model-level backstop. | One place builds/validates/persists postings in a `DB::transaction()`. Everything (UI, seeds, future import) funnels through it. SQLite can't CHECK cross-row sums, so balancing must live in app code. |
| 2 | **`brick/money`** wrapped behind a thin `App\Support\Money` abstraction. | Ships ISO-4217 exponents (USD=2, JPY=0, KWD=3), integer-safe math, per-currency format/parse. Wrapped so the app doesn't bind to the library. |
| 3 | **Beancount-style signed amounts.** Assets/expenses positive-normal; liabilities/income/equity negative-normal. `Σ amount` everywhere; sign-flip only in `AccountType::displaySign()`. | One balance formula for all types, zero type-branching in ledger math. Human-friendly signs happen only at the presentation edge. |
| 4 | **Two columns per posting:** native `amount`+`currency`, and `base_amount` (translated to the user's base currency). Books balance in **base currency** (`Σ base_amount = 0`). **Model A:** categories (income/expense) are always denominated in base. | Native amounts across different currencies can't sum to zero; base amounts always can. Categories stay a clean single-currency reporting surface; FX lives only on cards/wallets. |
| 5 | **Balances computed on read** (`Σ amount`). No stored balance column. Index `postings(account_id)`. | Postings are the only source of truth → drift is structurally impossible. Derived cache only if a real perf need appears. |
| 6 | **`kind` stored** as an enum, stamped by the service. | Cosmetic (picks the form, colors the row), never touches math. Single write path makes drift impossible, so storing is safe and enables fast filtering + intent capture (transfer-with-fee later). |
| 7 | **Four-layer per-user isolation:** global scope + `creating` auto-fill `user_id` + explicit ownership check of client-supplied account ids in the service + policies. `user_id` denormalized onto `postings`. | Can't forget to scope a read or write; the one leak (foreign id in a payload) is closed in the service. Denormalized `user_id` keeps scoped aggregates join-free. |
| 8 | **Hard delete + edit-as-atomic-replace.** No soft-deletes in the ledger. | Consistent with #5: no `deleted_at` for a balance query to overlook. Auditability comes from structure + `created_at`/`date`. A separate append-only audit log can be added later if needed. |
| 9 | **Base currency fixed at signup, immutable.** | `base_amount` is baked into history; changing base needs historical FX rates we deliberately don't store. Onboarding sets it once (`NOT NULL`). |
| 10 | **`ProvisionNewUserLedger` action** seeds at onboarding: hidden `equity` "Opening Balances" account (always), a curated default category set, and a starter "Cash" asset account. | The first expense must be recordable in seconds — it needs a category and an account to exist. A dedicated action (not `DatabaseSeeder`) is testable and reusable. |
| 11 | **FX input = two amounts** (foreign + base), implied rate read-only. Surfaced only when account currency ≠ base. Manual in v1. | Matches what the user observes (foreign price now, base charge from statement). No FX feed dependency. Cross-currency transfers reuse the same mechanic. |
| 12 | **v1 UI = core single-loop + flat lists + 2-line entry.** Splits (N-line) & hierarchy live in service/schema/tests but **no UI** yet. "Later features" out. | Model/service stay fully general so nothing retrofits; UI stays minimal for the first cut. |
| 13 | **Category hierarchy = leaf-only posting, 2-level, base only.** Parents are non-postable `is_group` headers; only leaves carry postings. A leaf's `parent_id` must be an owned, same-`type`, **root** group. Reads roll up subtree-wise (depth-agnostic); writes cap at 2 levels. **Categories first**; My-Account groups deferred. | Groups give summaries without losing leaf granularity; leaf-only keeps rollups unambiguous and posting currency well-defined. Capping depth keeps the picker and cycle-safety trivial while the schema stays general (deeper later = one rule change, no migration). |

---

## 4. Data model

### User
Owns everything; the per-person boundary.
- standard auth fields
- `base_currency` — e.g. `PEN`; fixed at signup, immutable (decision #9)
- every other entity carries `user_id` (global scope → full isolation)

### Account
Both cards/wallets and categories, distinguished by `type`.

| Field | Notes |
|---|---|
| `id`, `user_id`, `name` | |
| `type` | `asset` · `liability` · `income` · `expense` · `equity` |
| `currency` | non-null **only** for `asset`/`liability` (their native currency); **null** for `income`/`expense`/`equity` → always base (Model A) |
| `parent_id` | nullable; a leaf's parent must be a same-`type`, **root** `is_group` account (decision #13). Categories use it in v1; My Accounts deferred |
| `is_group` | non-postable grouping header that rolls up its children; carries no postings and `null` currency; immutable after creation (decision #13) |
| `icon`, `color`, `archived` | UI metadata; archived = hidden from pickers, still counted in history |

**Account types**

| Type | Bucket | Meaning | Normal sign | Currency |
|---|---|---|---|---|
| `asset` | My Accounts | money you have (checking, cash, debit) | + | own |
| `liability` | My Accounts | money you owe (credit card, loan) | − | own |
| `income` | Categories | where money comes from (salary, gifts) | − | base |
| `expense` | Categories | where money goes (groceries, rent) | + | base |
| `equity` | (hidden) | opening balances seed | − | base |

### Transaction (header / "entry")

| Field | Notes |
|---|---|
| `id`, `user_id` | |
| `date` | when it **happened** |
| `created_at` | when it was **entered** (keep both) |
| `payee`, `memo` | |
| `kind` | `expense` \| `income` \| `transfer` — stored enum, stamped by service; UI hint, never affects math |

### Posting (lines / splits — where double-entry lives)

| Field | Notes |
|---|---|
| `id`, `transaction_id`, `account_id`, `user_id` | `user_id` denormalized (decision #7) |
| `amount` | signed integer minor units, in the posting's `currency` |
| `currency` | |
| `base_amount` | signed integer minor units, in the user's `base_currency` |
| `memo` | nullable, per-line |

---

## 5. Invariants

1. For every transaction, `Σ base_amount over its postings = 0`.
2. Every transaction has **≥ 2 postings**.
3. `amount` / `base_amount` are always integer minor units.
4. A posting on an `asset`/`liability` account uses that account's `currency`.
5. All entities are scoped to the owning `user_id`.

`balance(account) = Σ amount` (native). `net worth = Σ base_amount` over asset + liability
accounts. Display sign is applied only by the presentation layer via `AccountType::displaySign()`
(+1 for asset/expense, −1 for liability/income/equity).

---

## 6. Worked examples

**Spend S/50 on groceries with PEN Visa** (single-currency, ~99% case):
```
Visa (liability)      amount −5000  PEN   base −5000
Groceries (expense)   amount +5000  PEN   base +5000   Σ base = 0 ✓
```

**Spend $100 on groceries with USD Amex, base PEN, $100 = S/370** (FX):
```
Amex (liability)      amount −10000 USD   base −37000
Groceries (expense)   amount +37000 PEN   base +37000  Σ base = 0 ✓
→ Amex balance −$100 (you owe); net worth counts −S/370
```

**Pay S/200 off the Visa from checking** (transfer — a simple log would mis-count as spend):
```
Checking (asset)      amount −20000 PEN   base −20000
Visa (liability)      amount +20000 PEN   base +20000  Σ base = 0 ✓  (no expense recorded)
```

**Opening balance: seed checking with S/1000**:
```
Checking (asset)            amount +100000 PEN   base +100000
Opening Balances (equity)   amount −100000 PEN   base −100000  Σ base = 0 ✓
```

---

## 7. Phased implementation plan

Built **bottom-up**: the ledger engine is fully built and tested before any UI. Every phase
ships with Pest tests and ends Pint- and Larastan-clean.

### Phase 0 — Primitives & schema (no UI)
- Add `brick/money`; build `App\Support\Money` wrapper (parse/format/minor-units per exponent).
- Backed enums `AccountType` (`displaySign()`, `isMyAccount()`/`isCategory()`, `usesNativeCurrency()`) and `TransactionKind`.
- `BelongsToUser` trait (global scope + `creating` auto-fill).
- Migrations: `accounts`, `transactions`, `postings` (`user_id` on postings, index on `account_id`, `parent_id` column, FK cascade).
- Models + relationships + factories.
- Tests: Money across USD/JPY/KWD exponents, enum sign/bucket logic, trait scoping.

### Phase 1 — Ledger engine `RecordTransaction` (no UI)
- The action: verify account ownership → build postings → compute `base_amount` (single = amount; FX = supplied base) → assert `Σ base = 0` & `≥ 2 postings` → stamp `kind` + `user_id` → `DB::transaction()`. Edit = full posting-set replace; hard delete with cascade.
- Model backstop (`assertBalanced()` on save).
- Balance + net-worth query methods.
- Tests: single-currency, FX, transfer, N-line split, opening balance; rejects unbalanced / < 2 postings / foreign account id; balances & net worth correct.

### Phase 2 — Onboarding & account management (first UI)
- Onboarding base-currency step → `ProvisionNewUserLedger`.
- Account/category CRUD (Inertia pages, "My Accounts" vs "Categories" split, archive), policies, Wayfinder routes.
- Tests: provisioning, scoped CRUD, archive semantics, onboarding gate.

### Phase 3 — Quick entry + transaction list (the core loop)
- Three-tab entry form → controller → `RecordTransaction`; currency auto-fill; FX two-amount reveal.
- Transaction list (eager-load `postings.account`, derive display, color by `kind`), edit/delete.
- Dashboard: per-account balances (display-sign applied) + net-worth total.
- Tests: each kind end-to-end (HTTP→DB), FX, edit, delete; Pest browser smoke test on the form.

### Phase 4 — Polish & hardening
- Empty states + skeletons (deferred props), validation messaging, opening-balance UI flow, consistent display-sign formatting, final Larastan/Pint pass.

### Phase 5 — Category hierarchy (leaf-only, 2-level)
Built bottom-up like the rest: constraints + rollups before any UI (decision #13).
- **5a (no UI):** `is_group` column; constrain `parent_id` (owned, same-`type`, root group; groups are parentless and currency-less); leaf-only posting rule in the transaction request (a transaction can never reference a group); subtree rollup balances (depth-agnostic); group create/update/delete rules (skip the opening-balance seed; block deleting a non-empty group). Tests for every constraint + rollup. Closes the underconstrained-`parent_id` gap.
- **5b (UI):** tree-rendered category list with rolled-up balances + add-group / add-subcategory; group toggle & parent picker in the account form; entry picker grouped with parent headers disabled; Pest browser smoke tests.

---

## 8. Later features (hang off this model cleanly)

- Budgets per category (target on an `expense` account over a period)
- Recurring transactions (subscriptions, rent)
- Reports & charts (spending by category, net worth over time)
- CSV / bank import
- Reconciliation (cleared/pending status on transactions)
- Split (N-line) **UI**, and hierarchy for **My Accounts** (category hierarchy ships in Phase 5; model already supports both)
- Explicit per-transaction FX rate + changeable base currency
- Append-only audit log
