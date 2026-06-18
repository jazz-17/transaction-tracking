# Phase 5 — Category Hierarchy · Implementation Working Doc

> **Working/scratch document.** Tracks the build of category hierarchy (decision #13).
> Delete once Phase 5 ships and `docs/transaction-tracking.md` is the sole reference.

## Goal

Leaf-only, 2-level category hierarchy: `Food → Groceries / Coffee`. You post to **leaves**;
**groups** (`Food`) are non-postable headers whose balance rolls up from their children.

## Locked decisions (from #13)

1. **Explicit `is_group` boolean** — not implicit "has children." A group is non-postable, carries no postings, has `null` currency, and is **immutable** after creation.
2. **2 levels enforced on writes** — a leaf's `parent_id` must point to a **root** group. Read/rollup code is **depth-agnostic** (subtree sum) so relaxing the cap later is a one-line change, no migration.
3. **Same-`type` parents only** — an `expense` leaf nests under an `expense` group, etc. Equity never participates.
4. **Delete a non-empty group → blocked** ("move or delete its children first"). Leaf delete keeps the existing seed-aware logic.
5. **Scope: Categories first.** Income/expense only (always base → trivial rollups). My-Account groups (mixed-currency, base-only rollup) deferred. Same model, no migration to add later.

This **replaces** the earlier "strip `parent_id` from the requests" interim fix — we constrain it instead of removing it.

---

## Phase 5a — Backend & constraints (no new UI)

Fully headless-testable. Makes the data safe and closes the underconstrained-`parent_id` finding.

- [x] **Migration** `add_is_group_to_accounts_table` — `boolean('is_group')->default(false)->after('parent_id')`.
- [x] **`Account` model** — added `is_group` to `$fillable`; cast `'is_group' => 'boolean'`; `isGroup()` helper. (Skipped the optional `scopeLeaves`/`scopePostable` — no consumer; the request rules and rollup query filter inline.)
- [x] **`StoreAccountRequest`**
  - `is_group` → `['boolean']`.
  - `currency` → required only when **not** a group **and** a My Account; forced `null` for groups (in the controller payload).
  - `parent_id` → if `is_group`: must be `null` (groups are roots) via `['nullable', 'prohibited']`; else (leaf): `Rule::exists` scoped to `user_id` **AND** same `type` **AND** `is_group = 1` **AND** `parent_id IS NULL` (root).
- [x] **`UpdateAccountRequest`** — same `parent_id` constraints, keyed off the bound account's `is_group`/`type` (re-homing a leaf allowed); `is_group` **not** accepted (immutable, like `type`/`currency`). Self-parent guarded via `whereNot('id', …)` as defense.
- [x] **`StoreTransactionRequest`** — `ownedAccount()` adds `->where('is_group', 0)`, so `account_id` / `category_id` can never be a group. This is the real server-side leaf-only guarantee (mirrors the equity exclusion).
- [x] **`AccountController::store`** — pass `is_group`; force `currency = null` for groups; groups skip opening-balance seeding (categories never seed anyway).
- [x] **`AccountController::present` / `index`** — group balance = Σ over its children via `rollUpGroupBalances()` (depth-agnostic subtree sum); groups have no own postings. Categories are base, so native == base. Returns `parent_id` + `is_group` so the frontend can nest.
- [x] **`AccountController::destroy`** — blocks when the account is a group with children (`children()->exists()`), with a "move or delete its children first" message. Leaf path unchanged.
- [x] **Tests** (`RecordTransactionTest`, `AccountControllerTest`, `TransactionControllerTest`):
  - reject: cross-`type` parent; parent that is a leaf (not a group); 2nd-level nesting (parent not a root); a group given a `parent_id`; posting (`account_id`/`category_id`) to a group.
  - accept: leaf under a group records normally.
  - rollup: a group's reported balance equals the sum of its children.
  - group has `null` currency; `is_group` is ignored on update (immutable); deleting a non-empty group is blocked.

> **Gotcha (validation):** the `Exists` rule string-casts `where()` values, so `(string) false === ''` matches no row. Use integer `0`/`1` for `is_group` in `Rule::exists`, never PHP booleans. Likewise `whereNot('id', …)`, not `where('id', '!=', …)` — `Exists::where()` takes no operator.

**Exit:** ✅ Pint + Larastan clean, all 5a tests green (69 in the three suites; 140 full suite). Shippable on its own.

---

## Phase 5b — UI

- [ ] **`accounts/Index.vue`** — render categories as groups with nested children; show rolled-up group balance; collapse/expand; "Add group" + "Add subcategory under …" actions.
- [ ] **`AccountFormDialog.vue`** — group toggle (creates an `is_group` header); for a leaf, optional parent selector listing only same-`type` root groups; hide the currency field for groups.
- [ ] **Transaction entry picker** — categories rendered grouped; **parent rows disabled** as headers; only leaves selectable.
- [ ] **Wayfinder** — regenerate if any route signatures change (`npm run build` / dev).
- [ ] **Pest browser smoke tests** — account form (create group + child) and the entry picker (group not selectable).

**Exit:** browser smoke green; manual check that group totals roll up and groups aren't postable.

---

## Open follow-ups (out of scope for Phase 5)

- My-Account grouping (mixed-currency groups → base-only rollup).
- N-line split UI.
- Budgets at the group level (actuals roll up from leaves — pairs cleanly with this model).
- Append-only audit log (separate Medium finding, parked).
