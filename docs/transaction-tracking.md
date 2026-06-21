# Transaction Tracking — Reference

A personal finance tracker. Log in, record what you spent (amount, card, category),
fast. Shared with family (each person gets a private ledger), robust enough to trust
with real money.

This document is the **single source of truth** for the design decisions, the data
model, the invariants, and the phased build. Update it when a decision changes.

> **2026 multi-currency redesign.** This supersedes the original "frozen base_amount /
> Model A" approach (old decisions #4, #11, #13). The ledger now stores **native amounts
> only** and derives every cross-currency figure on read. The rationale and the full new
> decision set are below.

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
as **signed integer minor units** in each posting's **own currency** — never floats, never
pre-translated. Any base-currency figure is **derived on read**, never frozen into a row.

---

## 3. Locked decisions

These were resolved during design review. Each is load-bearing — changing one ripples.

| # | Decision | Rationale |
|---|---|---|
| 1 | **Single `RecordTransaction` service is the sole write path.** Invariant enforced there **and** by a model-level backstop. | One place builds/validates/persists postings in a `DB::transaction()`. Everything (UI, seeds, future import) funnels through it. SQLite can't CHECK cross-row sums, so balancing must live in app code. |
| 2 | **`brick/money`** wrapped behind a thin `App\Support\Money` abstraction. | Ships ISO-4217 exponents (USD=2, JPY=0, KWD=3), integer-safe math, per-currency format/parse. Wrapped so the app doesn't bind to the library. |
| 3 | **Beancount-style signed amounts.** Assets/expenses positive-normal; liabilities/income/equity negative-normal. `Σ amount` everywhere; sign-flip only in `AccountType::displaySign()`. | One balance formula for all types, zero type-branching in ledger math. Human-friendly signs happen only at the presentation edge. |
| 4 | **Native amounts only — no stored `base_amount`, no stored rate.** Each posting carries its **own currency**; every base/rate figure is derived on read, never frozen. A single-currency transaction conserves exactly (`Σ amount = 0`); a **cross-currency** transaction (exactly two currencies, one being base) is a *swap of two observed amounts*, validated **structurally** — no weighted-sum check, no rate column. | Storing a translated `base_amount` *or* a derived `rate_to_base` is storing a derived value — the exact thing #5 forbids, and the root of phantom balances, FX-gain/loss machinery, staleness, edit-cascades, and rounding-tolerance bugs. Native-only keeps the books to **observed facts**; the rate of any exchange is recoverable as `−B/F` over its money legs (#11). |
| 5 | **Balances computed on read** (`Σ amount`). No stored balance column. Index `postings(account_id)`. | Postings are the only source of truth → drift is structurally impossible. Derived cache only if a real perf need appears. |
| 6 | **`kind` stored** as an enum, stamped by the service. | Cosmetic (picks the form, colors the row), never touches math. Single write path makes drift impossible, so storing is safe and enables fast filtering + intent capture. A `transfer` whose two legs differ in currency *is* an exchange — no separate kind needed. |
| 7 | **Four-layer per-user isolation:** global scope + `creating` auto-fill `user_id` + explicit ownership check of client-supplied account ids in the service + policies. `user_id` denormalized onto `postings`. | Can't forget to scope a read or write; the one leak (foreign id in a payload) is closed in the service. Denormalized `user_id` keeps scoped aggregates join-free. |
| 8 | **Hard delete + edit-as-atomic-replace.** No soft-deletes in the ledger. | Consistent with #5: no `deleted_at` for a balance query to overlook. Auditability comes from structure + `created_at`/`date`. With native-only storage (#4), editing history never strands a derived FX leg. |
| 9 | **Base currency fixed at signup, immutable.** | Under #4 the base is no longer forced onto every posting — it now serves two narrow roles: the **home/default currency** for new accounts, and the **one currency a cross-currency transaction must include** (#16, so every exchange is base↔foreign, never a cross-rate). Still immutable: changing it would reinterpret your history. |
| 10 | **`ProvisionNewUserLedger` action** seeds at onboarding: a hidden `equity` "Opening Balances" account (always, **multi-currency**), a curated default category set, and a starter "Cash" asset account. | The first expense must be recordable in seconds — it needs a category and an account to exist. No FX-gain/loss account is needed (it doesn't exist in this model). A dedicated action (not `DatabaseSeeder`) is testable and reusable. |
| 11 | **No transaction stores a rate.** A foreign purchase is single-currency in its own currency. An **exchange** is simply the **two real amounts you enter** (soles debited, dollars applied) — its rate is *derived on read* as `−B/F` (base-leg sum ÷ foreign-leg sum over the money legs), never stored. A **deviation guard** *warns and asks to confirm* (never blocks) when that implied rate grossly differs from your own last rate for the pair; the **first exchange sets the baseline**. **No FX feed.** | The two amounts are observed facts; no arithmetic validates a fact against itself, so the only meaningful numeric check is the *soft* guard against fat-fingers (a `$30` / `$3,000` slip). A base-currency fee nets out of `−B/F` cleanly; a foreign-currency fee is recorded as its **own** single-currency expense, keeping the exchange a clean 2-leg swap. |
| 12 | **v1 UI = core single-loop + flat lists + 2-line entry**; a transfer reveals a **second amount** when the two accounts' currencies differ. Splits (N-line) & My-Account grouping live in service/schema/tests but **no UI** yet. | Model/service stay fully general so nothing retrofits; UI stays minimal for the first cut. |
| 13 | **Category hierarchy = leaf-only posting, 2-level.** Parents are non-postable `is_group` headers; a leaf's `parent_id` must be an owned, same-`type`, **root** group. **Categories are multi-currency** (a leaf may hold postings in any currency); rollups sum **per-currency**. My-Account grouping still deferred. | Drops the old "categories are base-only" — that only existed to dodge a rate at purchase, which #4/#11 no longer require. Groups still give summaries without losing leaf granularity; depth cap keeps the picker and cycle-safety trivial. |
| 14 | **Currency rules per account type:** `asset`/`liability` are **currency-locked** (every posting in the account's own currency); `income`/`expense`/`equity` accept postings in **any** currency. | A card/wallet balance stays one clean number with no stray-currency leak; categories and equity must span currencies so foreign spend and foreign opening balances need no translation. |
| 15 | **Per-currency display, no blending.** Balances, spending, and net worth are shown **per-currency** (e.g. `S/5,000 · −$300`), never combined into one base total. A blended base figure — and the date-indexed rates table + read-time revaluation it requires — is **deferred**. | Honest and rate-free: no displayed number silently depends on a fluctuating rate. The blended view is a later read-layer over unchanged data. |
| 16 | **Conservation invariant:** a transaction is either **single-currency** (`Σ amount = 0`, exact) or a **two-currency swap including base** (validated **structurally**: ≤ 2 currencies, one is base — no weighted sum, no tolerance). **Three-or-more currencies, and foreign↔foreign, are rejected.** | A cross-currency exchange isn't a conservation event — it's a swap of two distinct assets, so there's no native sum to force to zero and no rate to round. Each account still conserves in its own currency on its own books. Keeps every exchange base↔foreign (no cross-rates) and validation trivial and exact. Schema stays general; relaxing later is a rule change, not a migration. |
| 17 | **A physical card with two currency lines = two flat single-currency accounts** (e.g. `Card · USD`, `Card · PEN`), created individually. A grouping header (and a card-with-lines create flow) arrives when My-Account grouping is un-deferred. | The bank truly maintains two sub-balances (soles line, dollars line) billed and paid separately. Two accounts preserve #14 and the clean exchange flow; a multi-currency account would muddy balances, payments, and the invariant. |

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
| `currency` | non-null **only** for `asset`/`liability` (their locked native currency, decision #14); **null** for `income`/`expense`/`equity`, which accept postings in any currency |
| `parent_id` | nullable; a leaf's parent must be a same-`type`, **root** `is_group` account (decision #13). Categories use it in v1; My Accounts deferred |
| `is_group` | non-postable grouping header that rolls up its children; carries no postings and `null` currency; immutable after creation (decision #13) |
| `icon`, `color`, `archived` | UI metadata; archived = hidden from pickers, still counted in history |

**Account types**

| Type | Bucket | Meaning | Normal sign | Currency |
|---|---|---|---|---|
| `asset` | My Accounts | money you have (checking, cash, debit) | + | own (locked) |
| `liability` | My Accounts | money you owe (credit card, loan) | − | own (locked) |
| `income` | Categories | where money comes from (salary, gifts) | − | per-posting (any) |
| `expense` | Categories | where money goes (groceries, rent) | + | per-posting (any) |
| `equity` | (hidden) | opening balances seed | − | per-posting (any) |

### Transaction (header / "entry")

| Field | Notes |
|---|---|
| `id`, `user_id` | |
| `date` | when it **happened** |
| `created_at` | when it was **entered** (keep both) |
| `payee`, `memo` | |
| `kind` | `expense` \| `income` \| `transfer` — stored enum, stamped by service; UI hint, never affects math. A cross-currency `transfer` is an exchange |

### Posting (lines / splits — where double-entry lives)

| Field | Notes |
|---|---|
| `id`, `transaction_id`, `account_id`, `user_id` | `user_id` denormalized (decision #7) |
| `amount` | signed integer minor units, in the posting's `currency` |
| `currency` | the currency actually moved on this line |
| `memo` | nullable, per-line |

> There is **no `base_amount` and no `rate_to_base` column** (decision #4). A posting's base
> value, when a report needs one, is derived; an exchange's rate is `−B/F` over its money legs
> (decision #11), and blended revaluation uses a future rates table (deferred, decision #15).

---

## 5. Invariants

1. Each transaction is **either**:
   - **single-currency** — all postings share one currency and `Σ amount = 0` (exact); **or**
   - a **two-currency swap** — exactly two currencies, **one of which is base** — accepted structurally; there is no weighted sum and no stored rate.
2. Every transaction has **≥ 2 postings**.
3. `amount` is always integer minor units. No rate or base figure is stored — both are derived on read.
4. A posting on an `asset`/`liability` account uses that account's locked `currency`; `income`/`expense`/`equity` accept any currency (decision #14).
5. **At most two distinct currencies** per transaction; if two, one **must** be base; foreign↔foreign is rejected (decision #16).
6. All entities are scoped to the owning `user_id`.

`balance(account) = Σ amount` per currency (asset/liability resolve to a single currency;
categories/equity may carry several). **Net worth** = per-currency `Σ amount` over asset +
liability accounts, reported as **buckets, not summed** (decision #15). Display sign is
applied only by the presentation layer via `AccountType::displaySign()` (+1 for
asset/expense, −1 for liability/income/equity).

---

## 6. Worked examples

**Spend S/50 on groceries with PEN Visa** (single-currency, the common case):
```
Visa (liability)      amount −5000  PEN
Groceries (expense)   amount +5000  PEN     Σ(PEN) = 0 ✓
```

**Spend $100 on groceries with the USD card** (single-currency — no PEN, no rate):
```
Card·USD (liability)  amount −10000 USD
Groceries (expense)   amount +10000 USD     Σ(USD) = 0 ✓
→ no base value stored; "$100" is what you see, derived to PEN only if a report asks
```

**Pay S/200 off the PEN Visa from checking** (transfer, single-currency):
```
Checking (asset)      amount −20000 PEN
Visa (liability)      amount +20000 PEN     Σ(PEN) = 0 ✓   (no expense recorded)
```

**Pay $300 off the USD card by converting soles** (exchange — the FX case):
```
Checking (asset)      amount −114000 PEN     ← S/1,140 leaves
Card·USD (liability)  amount  +30000 USD     ← $300 applied
→ kind = transfer; two observed amounts, no stored rate.
→ Structural check: 2 currencies, one is base (PEN) ✓
→ Card·USD conserves in USD on its own books: −30000 + 30000 = $0 (paid off exactly,
  no phantom, no FX gain/loss account). The PEN leg conserves on Checking's books.
→ Implied rate, derived on read: −B/F = 114000 / 30000 = 3.80 — fed to the deviation guard.
```

**Same payoff, with an S/10 PEN bank fee** (the fee nets out of `−B/F`):
```
Checking (asset)      amount −115000 PEN     ← S/1,150 leaves (S/1,140 + S/10 fee)
Fees & Charges (exp)  amount   +1000 PEN     ← S/10 fee recognized
Card·USD (liability)  amount  +30000 USD     ← $300 applied
→ B = Σ PEN = −115000 + 1000 = −114000;  F = Σ USD = 30000;  −B/F = 3.80 ✓ (fee excluded)
→ A *foreign*-currency fee is instead its own single-currency USD expense — never a leg here.
```

**Opening balance: a USD card you already owe $300 on** (native — no PEN field):
```
Card·USD (liability)        amount −30000 USD
Opening Balances (equity)   amount +30000 USD     Σ(USD) = 0 ✓
```

**Opening balance: seed checking with S/1000**:
```
Checking (asset)            amount +100000 PEN
Opening Balances (equity)   amount −100000 PEN    Σ(PEN) = 0 ✓
```

---

## 7. Phased rebuild plan

The original engine is replaced, not migrated — there is no meaningful ledger data, so
each phase reseeds. Built **bottom-up**, **test-first**: the ledger engine is fully proven
before any UI. Every phase ends Pint- and Larastan-clean.

> **Superseded in part.** R0/R1/R3 below describe the short-lived `rate_to_base` + weighted
> conservation check. That was removed by the *exchange-validation refactor*
> (`docs/refactor-plan-derive-rate-on-read.md`): no rate column, no weighted sum — a
> cross-currency transaction is validated structurally and its rate derived on read (`−B/F`),
> with the deviation guard as the soft protection. Read this section as build history; the
> normative rules are §3 decisions #4/#9/#11/#16 and §5.

### Phase R0 — Schema migration
- Drop `postings.base_amount`; add nullable `postings.rate_to_base` (fixed-precision decimal).
- `accounts.currency` stays nullable (now also the multi-currency categories/equity).
- Clean reseed; the single throwaway transaction is discarded.

### Phase R1 — `RecordTransaction` + invariant (no UI)
- New conservation check (decision #16): single-currency `Σ amount = 0`, or two-currency-with-base `Σ (amount × rate) = 0` within tolerance; ≤ 2 currencies; one must be base; foreign↔foreign rejected.
- Currency-lock check (decision #14): asset/liability legs in the account's currency; categories/equity any.
- Rate handling: the foreign leg of an exchange requires `rate_to_base`; all other legs `null`.
- Deviation-guard helper: own last-rate baseline; surfaced as a confirmable warning, never a hard block.
- Model backstop (`assertBalanced()`) updated to the new invariant.
- Tests: single-currency expense, USD-only expense (no rate), PEN transfer, USD↔PEN exchange (with and without a PEN fee leg), native opening balance (base & foreign). Rejects: 3-currency, foreign↔foreign, unbalanced weight, < 2 postings, wrong-currency on a locked account, foreign account id.

### Phase R2 — Accounts, provisioning, opening balances
- Equity multi-currency; `ProvisionNewUserLedger` reseed (no FX account).
- `StoreAccountRequest`: opening balance = a **single native amount** (delete `opening_balance_base` and the FX `after()` rule); seed via `RecordTransaction` against equity in the account's currency.
- `AccountFormDialog`: remove the PEN field; label the opening balance in the chosen currency.
- A dual-currency card is two separate account creations (decision #17).
- Tests: provisioning, native opening-balance seeding (base & foreign), currency-lock enforcement.

### Phase R3 — Entry UI (the core loop)
- Quick entry: native amount + account + category; **no base/PEN field anywhere**.
- Transfer: when *From*/*To* currencies differ, reveal the second amount; derive & show the rate; deviation-guard warning; submit as a `transfer` through `RecordTransaction`.
- Transaction list (eager-load `postings.account`, per-currency display, color by `kind`), edit/delete.
- Tests: each kind end-to-end (HTTP→DB) including the exchange; Pest browser smoke on entry + transfer.

### Phase R4 — Per-currency balances & net worth
- Account balances per currency (display-sign applied).
- Dashboard net worth = per-currency buckets (asset + liability).
- Spending by category, per-currency; multi-currency category rollups.
- Tests: balance & per-currency net-worth correctness; multi-currency rollup.

---

## 8. Later features (hang off this model cleanly)

- **Blended base net worth** via a date-indexed rates table + read-time revaluation (decision #15) — sourced from your own exchange rates + manual, optionally a PEN-capable feed.
- **FX rate feed** as a *replaceable convenience* layer (note: ECB/Frankfurter does **not** cover PEN).
- **My-Account grouping** → a proper dual-currency-card "create with lines" flow (decision #17).
- Budgets per category (target on an `expense` account over a period)
- Recurring transactions (subscriptions, rent)
- Reports & charts (spending by category, net worth over time)
- CSV / bank import
- Reconciliation (cleared/pending status on transactions)
- Split (N-line) **UI**
- Append-only audit log
