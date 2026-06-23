# Code Review — Multi-Currency Rebuild

Review of the uncommitted multi-currency redesign (native-only postings, `rate_to_base`,
conservation invariant, per-currency balances). Scope: `git diff HEAD` + the new
`app/Support/Ledger/TransactionBalance.php`.

**Overall:** the rebuild is solid. The conservation engine is well-factored (a single
`TransactionBalance` evaluator shared by the write path and the model backstop), the
native-only model is consistent end-to-end, and `RecordTransactionTest` proves the new
model thoroughly. The items below are the exceptions — one ships-broken bug, a
tolerance-design issue, and coverage gaps.

Status legend: ☐ open · ☑ done

> **Update (exchange-validation refactor, `docs/refactor-plan-derive-rate-on-read.md`).** The
> weighted conservation check and the stored `rate_to_base` were removed — a cross-currency
> transaction is now validated structurally and its rate derived on read (`−B/F`). That
> **resolved #3, #4, and #5/E, and removed the #D and partial-#K surface entirely** (no more
> `convertMinor`, no cross-currency conversion in the check). Findings #1/#2 and the minor
> display/order items remain open — they're independent of that refactor.

---

## 🔴 Correctness — must fix

### 1. ☐ Multi-currency category balances are blended across currencies and mislabeled as base
**`app/Http/Controllers/AccountController.php:36`** (`withSum('postings as balance_minor', 'amount')`)
→ **`:238`/`:253`** (`$currency = $account->currency ?? $base; … Money::ofMinor($displayMinor, $currency)`)

The diff removed the guard that kept categories base-only (deleted test
`rejects a category posting not denominated in base`, now `allows a category posting in any
currency`). Categories can now hold USD + PEN postings, but the account-list read path still
does a single `SUM(amount)`, blending all currencies into one integer and formatting it in
the user's base currency. `Account::balancesByCurrency()` was added for exactly this but **no
controller calls it**.

**Repro (shipped flow):** a `$100` USD purchase categorized to *Groceries* posts `+10000 USD`
to that expense account. `/accounts` shows Groceries as `PEN 100.00` (wrong symbol — it's
$100). Add a real `S/50` PEN expense → `balance_minor = 15000` → `PEN 150.00`, a meaningless
S/50 + $100 blend. Violates decision #15 ("per-currency, never blended").

**Fix:** render category balances from `balancesByCurrency()`, per-currency, the way the
dashboard net worth already does.

### 2. ☐ Group rollups blend children across currencies (same root as #1)
**`app/Http/Controllers/AccountController.php:211-227`** (`rollUpGroupBalances`)

The walk adds each leaf's `balance_minor` into its ancestors regardless of currency, then
`present()` renders one base-labeled figure. A *Food* group with a USD *Groceries* leaf and a
PEN *Coffee* leaf rolls up to a single nonsense number. The only rollup test uses all-PEN
children, so it's unguarded. The comment at **`:206-207`** ("Categories are base-denominated
(native == base), so no FX translation is involved") is now false and should be removed.

**Fix:** roll up per-currency (a currency→minor map per node), or scope rollups to a currency.

---

## 🟠 Ledger math — the fixed tolerance is magnitude-blind

**`app/Support/Ledger/TransactionBalance.php:29,136`** — `TOLERANCE_PER_FOREIGN_LEG = 1`,
checked as `abs($weight) > $foreignLegs * 1`. Wrong in both directions:

### 3. ☑ False reject of large / fine-grained exchanges — *resolved: weighted check removed*
The derived rate is rounded to 8 dp (`Money::deriveRate`, `Money.php:124`), so `convertMinor`'s
residue grows as `foreign_major × 0.5e-8 × 10^baseExp` while the tolerance stays at 1.

**Repro:** `S/10,000,000.00 ⇄ $30,000,000.00` (rate `0.33333333`) reconciles to weight `−10` →
`unbalanced` thrown for two internally-consistent amounts. Threshold ≈ 2M foreign units for a
2-decimal base; with a **3-decimal base (KWD)** the `×1000` factor drops it to a few hundred
thousand units. A legitimately balanced exchange gets blocked.

### 4. ☑ False accept by splitting foreign legs — *resolved: weighted check removed*
Because the bound scales with `$foreignLegs`, a 2-foreign-leg exchange off by S/0.02
(`−10002` base vs two USD legs converting to `+5000` each) passes (`abs(−2) > 2` is false),
while the same S/0.02 imbalance on one leg is correctly rejected. Not reachable from the
single-leg controller flow today, but `RecordTransaction` is the documented sole write path
for splits/imports and the backstop runs this too.

**Fix (for 3 & 4):** derive the tolerance from the legs (≤ 0.5 base-minor-unit of rounding per
foreign leg, and/or scale against amount magnitude / compare each foreign leg's own residue),
not a flat `1 × legCount`.

---

## ☑ Deviation guard — *resolved: implemented*

Was: the only protection against a fat-fingered exchange amount, and it didn't exist.
Now implemented as `App\Support\Ledger\RateDeviationGuard` (warn-and-confirm, never blocks;
first exchange for a pair sets the baseline; a >2× swing surfaces `confirm_rate`, which the
entry dialog acknowledges with "Record anyway"). The 2× band lives in one place
(`RateDeviationGuard::FACTOR`) and is shared with the client. Beyond the on-submit check, the
entry form now shows a **proactive** reference while typing — the pair's last rate and the live
implied rate (both priced base-per-foreign, the same orientation the guard uses), turned amber
past the band, with a first-time nudge when a pair has no baseline yet. Covered by feature tests
in `TransactionControllerTest` (baseline-silent, within-band, gross-deviation, confirm-proceeds,
both sides of the 2× boundary, and the `lastRates`/`rateBand` form contract).

---

## 🟡 Test coverage gaps (real logic with no test behind it)

- ☑ **Foreign-as-source exchange (USD→PEN) is untested** — *resolved.* `transferPostings` no
  longer branches on `fromIsBase` (it's a plain two-leg swap), and `TransactionControllerTest`
  now covers both `records a base→foreign transfer` and `records a foreign→base transfer`.
- ☐ **`Money::deriveRate` has no direct unit test, and no cross-exponent exchange is exercised.**
  (`convertMinor` is gone, so the cross-exponent *conservation* risk is removed; `deriveRate` is
  now exercised indirectly by the deviation-guard tests.) A direct `MoneyTest` for `deriveRate`
  and a JPY exchange end-to-end would still close the gap. Minor.
- ☐ **No test drives one category holding two currencies** — the headline behavior of decision
  #14. Every `balancesByCurrency()` assertion uses a single-currency bucket, which is why bug
  #1 slipped through. A Groceries-with-PEN-and-USD test would have caught it.
- ☐ **Order-dependent `toBe()` on a `GROUP BY` result.**
  `tests/Feature/Ledger/RecordTransactionTest.php:215` asserts `['PEN' => …, 'USD' => …]`
  ordering, which passes only because SQLite emits PEN first (`===` is order-sensitive) and
  `netWorth()` (`User.php:75`) has no `orderBy`. Sort the buckets deterministically (in
  `netWorth()` or the test). (`DashboardTest` is single-bucket today, so it carries no ordering
  risk yet — but a multi-bucket dashboard test would inherit the same trap.)
- ☐ **No Dashboard feature test** with multiple net-worth buckets or the empty-state `—` branch
  (`Dashboard.vue:60-65`).

---

## ⚪ Minor / edge

- ☑ **`Transaction::isBalanced()` exception leak** — *resolved.* `convertMinor` is gone; the
  check is now pure integer/structural work, so there's no Brick exception to escape the
  `catch (InvalidTransactionException)`.
- ☑ **Write-path vs backstop rate divergence (>8 dp)** — *resolved.* No rate is stored or
  re-evaluated, so the two paths can no longer disagree on a rounded rate.
- ☐ **Exchange rows show only the destination leg** (`present():203`,
  `firstWhere('amount','>',0)`): the card-payoff shows `$300`, never the `S/1,140` leaving
  checking, and the displayed currency flips by transfer direction. Presentation-only.
- ☐ **Original migration edited in place** (`2026_06_17_171636_create_postings_table.php`)
  rather than a new migration — against CLAUDE.md ("use `php artisan make:migration`") and
  requires `migrate:fresh`. Fine for greenfield, but a `Dockerfile`/`DEPLOY.md` land in the
  same batch, so make it a deliberate call for prod.
- ☐ **`Dashboard.vue:25`** — `baseCurrency` prop is now passed but unused.

---

## Verification at review time

- 150 Pest tests pass (4 browser tests skipped); Pint / vue-tsc / ESLint / Larastan clean.
- Grep confirms no lingering references to removed symbols (`base_amount`, `baseBalance`,
  `opening_balance_base`, `netWorthDisplay`) outside an intentional migration comment.
- Checked and found correct: `transferPostings` rate derivation/leg assignment in both
  directions; `StoreTransactionRequest::after()` accept/reject logic (incl. `assertScale`
  no-op on unfilled fields); `editPayload` exchange round-trip; `User::netWorth()` shape vs its
  only caller; `impliedRate` NaN/division guards.
