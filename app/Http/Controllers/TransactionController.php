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
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function __construct(private readonly RecordTransaction $record) {}

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
            ->get(['id', 'name', 'type']);

        return Inertia::render('transactions/Index', [
            // Deferred: the list can grow unbounded, so the page shell (and the entry
            // form's account/category data) renders instantly while history streams in.
            'transactions' => Inertia::defer(fn (): array => Transaction::query()
                ->with('postings.account')
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Transaction $transaction): array => $this->present($transaction, $base))
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
        [$kind, $postings] = $this->compose($data, (string) $request->user()->base_currency);

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
        [$kind, $postings] = $this->compose($data, (string) $request->user()->base_currency);

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
     * expects. Native amounts live in the money account's currency; categories are
     * always denominated in base (Model A, decision #4); the supplied base amount only
     * matters when an FX leg is involved (decision #11).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: TransactionKind, 1: array<int, PostingInput>}
     */
    private function compose(array $data, string $base): array
    {
        $kind = TransactionKind::from($data['kind']);

        if ($kind === TransactionKind::Transfer) {
            return [$kind, $this->transferPostings($data, $base)];
        }

        $money = Account::query()->findOrFail((int) $data['account_id']);
        $currency = (string) $money->currency;

        $amount = Money::parse($data['amount'], $currency)->minorUnits;
        $baseAmount = $currency === $base
            ? $amount
            : Money::parse($data['base_amount'], $base)->minorUnits;

        // Income flows money in (+) and the category records earnings (− normal);
        // expense flows money out (−) and the category records spend (+ normal).
        $sign = $kind === TransactionKind::Income ? 1 : -1;

        return [$kind, [
            new PostingInput($money->id, $sign * $amount, $currency, $sign * $baseAmount),
            new PostingInput((int) $data['category_id'], -$sign * $baseAmount, $base, -$sign * $baseAmount),
        ]];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, PostingInput>
     */
    private function transferPostings(array $data, string $base): array
    {
        $from = Account::query()->findOrFail((int) $data['from_account_id']);
        $to = Account::query()->findOrFail((int) $data['to_account_id']);
        $fromCurrency = (string) $from->currency;
        $toCurrency = (string) $to->currency;

        $fromAmount = Money::parse($data['amount'], $fromCurrency)->minorUnits;
        $toAmount = $toCurrency === $fromCurrency
            ? $fromAmount
            : Money::parse($data['to_amount'], $toCurrency)->minorUnits;

        $baseAmount = match (true) {
            $fromCurrency === $base => $fromAmount,
            $toCurrency === $base => $toAmount,
            default => Money::parse($data['base_amount'], $base)->minorUnits,
        };

        return [
            new PostingInput($from->id, -$fromAmount, $fromCurrency, -$baseAmount),
            new PostingInput($to->id, $toAmount, $toCurrency, $baseAmount),
        ];
    }

    /**
     * Shape one transaction for the history list: a display amount + direction for
     * coloring, a human summary, and an `edit` payload that pre-fills the form for the
     * 2-line shape the UI can create (splits have no edit form yet, decision #12).
     *
     * @return array<string, mixed>
     */
    private function present(Transaction $transaction, string $base): array
    {
        /** @var EloquentCollection<int, Posting> $postings */
        $postings = $transaction->postings;

        $inflow = (int) $postings->where('base_amount', '>', 0)->sum('base_amount');
        $money = $postings->filter(fn (Posting $posting): bool => $posting->account->type->isMyAccount());
        $categories = $postings->filter(fn (Posting $posting): bool => $posting->account->type->isCategory());

        return [
            'id' => $transaction->id,
            'kind' => $transaction->kind->value,
            'date' => $transaction->date->toDateString(),
            'date_label' => $transaction->date->isoFormat('MMM D, YYYY'),
            'payee' => $transaction->payee,
            'memo' => $transaction->memo,
            'summary' => $this->summary($transaction->kind, $money, $categories),
            'account_label' => $money->map(fn (Posting $posting): string => $posting->account->name)->implode(', '),
            'amount_display' => Money::ofMinor($inflow, $base)->format(),
            'direction' => match ($transaction->kind) {
                TransactionKind::Income => 'in',
                TransactionKind::Expense => 'out',
                TransactionKind::Transfer => 'transfer',
            },
            'edit' => $this->editPayload($transaction, $base, $money, $categories),
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
    private function editPayload(Transaction $transaction, string $base, EloquentCollection $money, EloquentCollection $categories): ?array
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
                'base_amount' => Money::ofMinor(abs($from->base_amount), $base)->amount(),
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
            'base_amount' => Money::ofMinor(abs($moneyPosting->base_amount), $base)->amount(),
        ];
    }

    /**
     * @param  EloquentCollection<int, Account>  $categories
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(EloquentCollection $categories, AccountType $type): array
    {
        return array_values(
            $categories
                ->where('type', $type)
                ->map(fn (Account $account): array => ['id' => $account->id, 'name' => $account->name])
                ->all()
        );
    }
}
