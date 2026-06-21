# Refactor Plan — Drop the stored exchange rate & the weighted conservation check

> **Status: implemented.** All phases (0–5) landed. `rate_to_base` and the weighted check are
> gone; cross-currency transactions are validated structurally with the rate derived on read
> (`−B/F`); the `RateDeviationGuard` provides the soft warn-and-confirm. Suite green (154
> passed, 4 browser skipped); Pint / Larastan / vue-tsc / ESLint clean.

**Goal.** A cross-currency transaction is a swap of two observed amounts, not a conservation
event. Stop forcing it into a weighted `Σ(amount × rate) = 0` check, and stop storing a
derived `rate_to_base`. Validate two-currency transactions **structurally** only; keep the
exact single-currency `Σ amount = 0`. Derive any rate on read as `−B/F`. Add the soft
**deviation guard** as the real (advisory) protection against typos.

## Why — what this dissolves

Removing the weight check and the stored rate eliminates, by construction, these review
findings (`docs/code-review-multicurrency-rebuild.md`):

- **#3** false-reject of large / 3-decimal-base exchanges (tolerance vs rate round-trip)
- **#4** false-accept by splitting foreign legs (per-leg tolerance accumulation)
- **#5 / E** write-path vs backstop rate disagreement for >8-dp rates
- **D** `isBalanced()` exception leak via `convertMinor` overflow
- **K (partial)** cross-exponent conversion risk — the ledger no longer converts currencies at all

The rate is 100% redundant with the leg amounts: for any single-foreign-rate transaction,
`rate = −B/F` where **B** = Σ base-leg amounts and **F** = Σ foreign-leg amounts. A
base-currency fee nets out cleanly; the only non-recoverable cases (a *foreign*-currency fee,
or two rates in one transaction) are handled by **modelling** (book a foreign fee as its own
single-currency expense), not by storing a rate.

## Decision to lock first

**Drop the `postings.rate_to_base` column** (recommended) rather than keep it as an
unvalidated annotation. Nothing reads it today except the check we're deleting; the deferred
revaluation feature needs a date-indexed rates table, not a per-transaction rate. If an audit
trail of "the rate I got" is wanted later, derive `−B/F` on read or add an explicitly
*unvalidated* note column then — never something the ledger validates against.

Amend `docs/transaction-tracking.md` decisions **#4, #11, #16** + worked examples to match,
*before* coding (the doc is the source of truth).

---

## Phase 0 — Lock the design + schema

- Amend `transaction-tracking.md` (#4: no `rate_to_base`; #11: no stored rate, `−B/F` on read,
  deviation guard is the soft protection; #16: two-currency = structural only — ≤2 currencies,
  one is base — no weighted sum, no tolerance). Update the worked examples (drop the `rate`
  columns and the `Σ(amount × rate)` line).
- **Schema:** drop `rate_to_base`.
  - Greenfield / clean-reseed (current state) → edit the create migration
    (`2026_06_17_171636_create_postings_table.php`) to remove the column. Simplest.
  - If any environment has already run it (prod prep via the new Dockerfile) → a new
    `php artisan make:migration drop_rate_to_base_from_postings` with `up`/`down`.

## Phase 1 — Evaluator + DTO + models (no UI, test-first) — the core

This is the heart; do it test-first against the ledger spec.

- **`app/Support/Ledger/TransactionBalance.php`** — rewrite. Keep: `count < 2` →
  `tooFewPostings`; single-currency → `Σ amount = 0` (exact, `unbalanced`); `> 2` currencies →
  `tooManyCurrencies`; 2 currencies without base → `exchangeWithoutBase`. **Remove:**
  `assertExchange`, the weight loop, `convertMinor`, `TOLERANCE_PER_FOREIGN_LEG`,
  `missingRate`, `unexpectedRate`. Two-currency-with-base is now **accepted as-is**.
  Lines drop the `rate` key → `array{amount: int, currency: string}`.
- **`PostingInput`** — remove `?string $rateToBase`. Back to `{accountId, amount, currency, memo}`.
- **`Posting`** — remove `rate_to_base` from `#[Fillable]`, `casts()`, and the propdoc.
- **`RecordTransaction`** — drop `rate` from the `TransactionBalance::assert` line mapping and
  the `$posting->rate_to_base = …` write. Currency-lock check stays.
- **`Transaction`** — `persistedLines()` drops `rate_to_base` / the `rate` key. `assertBalanced`
  / `isBalanced` unchanged (now structural; the `catch (InvalidTransactionException)` leak in
  `isBalanced` is gone because `convertMinor` is gone).
- **`Money`** — delete `convertMinor` (dead). Keep `deriveRate` **only if** Phase 4 ships in
  this batch; otherwise it's dead too — delete and re-add with the guard.
- **`InvalidTransactionException`** — delete `missingRate`, `unexpectedRate`. Keep
  `tooManyCurrencies`, `exchangeWithoutBase`, `tooFewPostings`, `unbalanced`.
- **`PostingFactory`** — already `rate_to_base => null`; remove the key entirely.

**Tests — `RecordTransactionTest`:**
- Strip the `rate` 4th arg from every `PostingInput`.
- **Delete** (no longer meaningful): `rejects an exchange whose weighted sum does not balance`,
  `rejects an exchange foreign leg with no rate`, `rejects a rate on a single-currency transaction`.
- **Update**: the exchange tests assert balances + that no rate is persisted; the fee-leg test
  becomes a 3-posting structural-pass with no rate.
- **Keep/confirm** structural rejections: 3-currency, foreign↔foreign, < 2 postings,
  wrong-currency on a locked account, foreign account id, single-currency `Σ ≠ 0`.
- **Add** (review gap J): a base→foreign **and** a foreign→base exchange both record correctly.

## Phase 2 — Controller + request + entry UI

- **`TransactionController::transferPostings`** — collapses: `from` leg `−fromMinor`
  (`fromCurrency`), `to` leg `+toMinor` (`toCurrency`); same-currency uses `fromMinor` for both.
  Remove `deriveRate`, `$fromIsBase`, and rate assignment.
- **`StoreTransactionRequest`** — unchanged (the "must involve base" + "`to_amount` required"
  structural rules already live here and stay).
- **`TransactionFormDialog.vue`** — `impliedRate` already derives from the two amounts; no change
  for the core refactor (it becomes the guard's display surface in Phase 4).
- **Tests — `TransactionControllerTest`:** remove the "snapshotting the rate" assertions; assert
  balances + no persisted rate; add the foreign→base direction.

## Phase 3 — Fees

- **`ProvisionNewUserLedger`** — add `'Fees & Charges'` to `EXPENSE_CATEGORIES` (one general
  expense category; do not split FX/ATM/card; do **not** add any FX-gain/loss account).
- Update the provisioning test's category count/set assertions.
- Doc only: a foreign-currency fee is recorded as its own single-currency expense transaction
  (keeps the exchange a clean 2-leg `−B/F`).

## Phase 4 — Deviation guard (separable; can be its own PR)

The soft, advisory protection that the hard check never provided.

- **Helper** (`App\Support\Ledger\…`) — compute this transaction's rate via `deriveRate` on the
  money legs (`−B/F`, excluding category legs); look up the user's **last** rate for the
  currency pair (derive it the same way from prior transactions); return a warning when the
  ratio deviates beyond a threshold. **First exchange for a pair sets the baseline** (no warning).
- **Flow** — warn-and-confirm, never block: surface the warning in the response; a `confirm`
  flag in the payload proceeds. Wire through `StoreTransactionRequest` / controller / the Vue
  dialog (reuse the `impliedRate` line as the warning surface).
- **Tests** — baseline (first exchange silent), within-threshold silent, gross deviation warns,
  confirm proceeds, never hard-blocks.

**Open design questions** (decide at Phase 4): threshold (e.g. ±X% or an order-of-magnitude
band?); per-currency-pair vs global last-rate; how "last" is scoped (most recent by date).

## Phase 5 — Doc bookkeeping

- Mark findings #3/#4/#5 (and D, partial K) **resolved** in
  `docs/code-review-multicurrency-rebuild.md`.
- Add the `−B/F` recovery rule + "foreign fee = its own transaction" note to
  `transaction-tracking.md` (decision-note amendment).

---

## Out of scope (track separately)

- Review **#1/#2** — multi-currency category balances blended in `AccountController`
  (`withSum`/`rollUpGroupBalances`). Real bug, but independent of the exchange refactor.
- Review **G** — exchange rows show only the destination leg (`present()`), presentation-only.
- Review **I** — `netWorth()` `toBe()` order-dependence; optional drive-by (sort the buckets).

## Verification (each phase)

`php artisan test --compact` (filter to the touched file first), then
`vendor/bin/pint --dirty --format agent`, `vendor/bin/phpstan` (Larastan), and for Phases 2/4
`vue-tsc` + ESLint. Suite stays green at every phase boundary.
