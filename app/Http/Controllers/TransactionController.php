<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\PostingInput;
use App\Actions\Transactions\RecordTransaction;
use App\Enums\AccountType;
use App\Enums\TransactionKind;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Account;
use App\Models\Posting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Ledger\RateDeviationGuard;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function __construct(
        private readonly RecordTransaction $record,
        private readonly RateDeviationGuard $rateGuard,
    ) {}

    public function index(Request $request): Response
    {
        $base = (string) $request->user()->base_currency;

        $accounts = Account::query()
            ->whereIn('type', [AccountType::Asset->value, AccountType::Liability->value])
            ->where('archived', false)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'currency'])
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'currency' => (string) $account->currency,
            ]);

        $categories = Account::query()
            ->whereIn('type', [AccountType::Income->value, AccountType::Expense->value])
            ->where('archived', false)
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'parent_id', 'is_group']);

        return Inertia::render('transactions/Index', [
            // Deferred: the list can grow unbounded, so the page shell (and the entry
            // form's account/category data) renders instantly while history streams in.
            'transactions' => Inertia::defer(fn (): array => Transaction::query()
                ->with('postings.account')
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Transaction $transaction): array => $this->present($transaction))
                ->all()),
            'accounts' => $accounts->values(),
            'expenseCategories' => $this->categoryOptions($categories, AccountType::Expense),
            'incomeCategories' => $this->categoryOptions($categories, AccountType::Income),
            'baseCurrency' => $base,
        ]);
    }

    public function store(StoreTransactionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        [$kind, $postings] = $this->compose($data);
        $this->guardRate($postings, $request->user(), $request->boolean('confirm_rate'));

        $this->record->create(
            $request->user(),
            $kind,
            $data['date'],
            $postings,
            $data['payee'] ?? null,
            $data['memo'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transaction recorded.']);

        return back();
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): RedirectResponse
    {
        Gate::authorize('update', $transaction);

        $data = $request->validated();
        [$kind, $postings] = $this->compose($data);
        $this->guardRate($postings, $request->user(), $request->boolean('confirm_rate'), (int) $transaction->id);

        $this->record->update(
            $transaction,
            $kind,
            $data['date'],
            $postings,
            $data['payee'] ?? null,
            $data['memo'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transaction updated.']);

        return back();
    }

    public function destroy(Transaction $transaction): RedirectResponse
    {
        Gate::authorize('delete', $transaction);

        $this->record->delete($transaction);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Transaction deleted.']);

        return back();
    }

    /**
     * Turn the validated quick-entry payload into the balanced posting set the ledger
     * expects. Every leg is in native currency (decision #4): an expense/income is
     * single-currency (money + category share the currency); a transfer is single-currency,
     * or — when the two accounts differ — a swap of the two observed amounts, no rate
     * stored (decision #11/#16).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: TransactionKind, 1: array<int, PostingInput>}
     */
    private function compose(array $data): array
    {
        $kind = TransactionKind::from($data['kind']);

        if ($kind === TransactionKind::Transfer) {
            return [$kind, $this->transferPostings($data)];
        }

        $money = Account::query()->findOrFail((int) $data['account_id']);
        $currency = (string) $money->currency;
        $amount = Money::parse($data['amount'], $currency)->minorUnits;

        // Income flows money in (+) and the category records earnings (− normal); expense
        // flows money out (−) and spend (+ normal). The category leg takes the SAME currency
        // as the money leg (categories are multi-currency, decision #14) — single-currency,
        // no base, no rate.
        $sign = $kind === TransactionKind::Income ? 1 : -1;

        return [$kind, [
            new PostingInput($money->id, $sign * $amount, $currency),
            new PostingInput((int) $data['category_id'], -$sign * $amount, $currency),
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, PostingInput>
     */
    private function transferPostings(array $data): array
    {
        $from = Account::query()->findOrFail((int) $data['from_account_id']);
        $to = Account::query()->findOrFail((int) $data['to_account_id']);
        $fromCurrency = (string) $from->currency;
        $toCurrency = (string) $to->currency;

        $fromMinor = Money::parse($data['amount'], $fromCurrency)->minorUnits;

        // Same-currency transfer reuses the one amount; a cross-currency swap carries the
        // destination's own observed amount (decision #16). Two facts, no rate — the
        // structural "must involve base" rule is enforced in StoreTransactionRequest.
        $toMinor = strtoupper($fromCurrency) === strtoupper($toCurrency)
            ? $fromMinor
            : Money::parse($data['to_amount'], $toCurrency)->minorUnits;

        return [
            new PostingInput($from->id, -$fromMinor, $fromCurrency),
            new PostingInput($to->id, $toMinor, $toCurrency),
        ];
    }

    /**
     * Soft deviation guard (decision #11): if the composed exchange's implied rate is far from
     * the user's last rate for that pair, halt and ask the user to confirm — unless they already
     * did (`confirm_rate`). Never a hard block; a confirmed submit skips the check entirely.
     *
     * @param  array<int, PostingInput>  $postings
     *
     * @throws ValidationException
     */
    private function guardRate(array $postings, User $user, bool $confirmed, ?int $excludeTransactionId = null): void
    {
        if ($confirmed) {
            return;
        }

        $warning = $this->rateGuard->warn($user, (string) $user->base_currency, $postings, $excludeTransactionId);

        if ($warning !== null) {
            throw ValidationException::withMessages(['confirm_rate' => $warning]);
        }
    }

    /**
     * Shape one transaction for the history list: a display amount + direction for
     * coloring, a human summary, and an `edit` payload that pre-fills the form for the
     * 2-line shape the UI can create (splits have no edit form yet, decision #12).
     *
     * @return array<string, mixed>
     */
    private function present(Transaction $transaction): array
    {
        /** @var EloquentCollection<int, Posting> $postings */
        $postings = $transaction->postings;

        $money = $postings->filter(fn (Posting $posting): bool => $posting->account->type->isMyAccount());
        $categories = $postings->filter(fn (Posting $posting): bool => $posting->account->type->isCategory());

        // Per-currency display (decision #15): show the amount in its own currency — the
        // destination leg for a transfer, the money leg otherwise.
        $displayPosting = $transaction->kind === TransactionKind::Transfer
            ? ($money->firstWhere('amount', '>', 0) ?? $money->first())
            : $money->first();

        return [
            'id' => $transaction->id,
            'kind' => $transaction->kind->value,
            'date' => $transaction->date->toDateString(),
            'date_label' => $transaction->date->isoFormat('MMM D, YYYY'),
            'payee' => $transaction->payee,
            'memo' => $transaction->memo,
            'summary' => $this->summary($transaction->kind, $money, $categories),
            'account_label' => $money->map(fn (Posting $posting): string => $posting->account->name)->implode(', '),
            'amount_display' => $displayPosting === null
                ? ''
                : Money::ofMinor(abs((int) $displayPosting->amount), $displayPosting->currency)->format(),
            'direction' => match ($transaction->kind) {
                TransactionKind::Income => 'in',
                TransactionKind::Expense => 'out',
                TransactionKind::Transfer => 'transfer',
            },
            'edit' => $this->editPayload($transaction, $money, $categories),
        ];
    }

    /**
     * @param  EloquentCollection<int, Posting>  $money
     * @param  EloquentCollection<int, Posting>  $categories
     */
    private function summary(TransactionKind $kind, EloquentCollection $money, EloquentCollection $categories): string
    {
        if ($kind === TransactionKind::Transfer) {
            $from = $money->firstWhere('amount', '<', 0);
            $to = $money->firstWhere('amount', '>', 0);

            return trim(($from?->account->name ?? '?').' → '.($to?->account->name ?? '?'));
        }

        return $categories->map(fn (Posting $posting): string => $posting->account->name)->implode(', ');
    }

    /**
     * The 2-line shape the entry form produces, mapped back to form fields so a row can
     * be edited in place. Returns null for splits/opening balances the UI can't edit.
     *
     * @param  EloquentCollection<int, Posting>  $money
     * @param  EloquentCollection<int, Posting>  $categories
     * @return array<string, mixed>|null
     */
    private function editPayload(Transaction $transaction, EloquentCollection $money, EloquentCollection $categories): ?array
    {
        if ($transaction->postings->count() !== 2) {
            return null;
        }

        $shared = [
            'kind' => $transaction->kind->value,
            'date' => $transaction->date->toDateString(),
            'payee' => $transaction->payee,
            'memo' => $transaction->memo,
        ];

        if ($transaction->kind === TransactionKind::Transfer) {
            $from = $money->firstWhere('amount', '<', 0);
            $to = $money->firstWhere('amount', '>', 0);
            if ($from === null || $to === null) {
                return null;
            }

            return [
                ...$shared,
                'from_account_id' => $from->account_id,
                'to_account_id' => $to->account_id,
                'amount' => Money::ofMinor(abs($from->amount), $from->currency)->amount(),
                'to_amount' => Money::ofMinor(abs($to->amount), $to->currency)->amount(),
            ];
        }

        $moneyPosting = $money->first();
        $categoryPosting = $categories->first();
        if ($moneyPosting === null || $categoryPosting === null) {
            return null;
        }

        return [
            ...$shared,
            'account_id' => $moneyPosting->account_id,
            'category_id' => $categoryPosting->account_id,
            'amount' => Money::ofMinor(abs($moneyPosting->amount), $moneyPosting->currency)->amount(),
        ];
    }

    /**
     * Category options for the entry picker, nested one level (decision #13): each root
     * group becomes a non-selectable header carrying its leaf children, and ungrouped
     * leaves sit at the top level. Only leaves are postable, so a group exposes no id the
     * form can submit. Childless groups are omitted — they'd offer nothing to pick.
     *
     * @param  EloquentCollection<int, Account>  $categories
     * @return list<array{id: int, name: string, is_group: bool, children?: list<array{id: int, name: string, is_group: bool}>}>
     */
    private function categoryOptions(EloquentCollection $categories, AccountType $type): array
    {
        $ofType = $categories->where('type', $type);
        $childrenByParent = $ofType->whereNotNull('parent_id')->groupBy('parent_id');

        $options = [];

        // $ofType keeps the query's name ordering, so roots and their children stay sorted.
        foreach ($ofType->whereNull('parent_id') as $account) {
            if (! $account->isGroup()) {
                $options[] = ['id' => $account->id, 'name' => $account->name, 'is_group' => false];

                continue;
            }

            $children = array_values(
                $childrenByParent->get($account->id, collect())
                    ->map(fn (Account $child): array => ['id' => $child->id, 'name' => $child->name, 'is_group' => false])
                    ->all()
            );

            if ($children === []) {
                continue;
            }

            $options[] = ['id' => $account->id, 'name' => $account->name, 'is_group' => true, 'children' => $children];
        }

        return $options;
    }
}
