<?php

namespace App\Http\Controllers;

use App\Actions\Transactions\PostingInput;
use App\Actions\Transactions\RecordTransaction;
use App\Enums\AccountType;
use App\Enums\TransactionKind;
use App\Http\Requests\StoreAccountRequest;
use App\Http\Requests\UpdateAccountRequest;
use App\Models\Account;
use App\Models\User;
use App\Support\Currencies;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function __construct(private readonly RecordTransaction $record) {}

    public function index(Request $request): Response
    {
        $base = (string) $request->user()->base_currency;

        $accounts = Account::query()
            ->where('type', '!=', AccountType::Equity->value)
            ->withSum('postings as balance_minor', 'amount')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account): array => $this->present($account, $base));

        return Inertia::render('accounts/Index', [
            'myAccounts' => $accounts->whereIn('type', [
                AccountType::Asset->value,
                AccountType::Liability->value,
            ])->values(),
            'categories' => $accounts->whereIn('type', [
                AccountType::Income->value,
                AccountType::Expense->value,
            ])->values(),
            'currencies' => Currencies::options(),
            'baseCurrency' => $base,
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $type = AccountType::from($data['type']);

        $account = Account::create([
            'name' => $data['name'],
            'type' => $type,
            'currency' => $type->usesNativeCurrency() ? strtoupper((string) $data['currency']) : null,
            'parent_id' => $data['parent_id'] ?? null,
            'icon' => $data['icon'] ?? null,
            'color' => $data['color'] ?? null,
        ]);

        if ($type->usesNativeCurrency()) {
            $this->seedOpeningBalance($request->user(), $account, $data);
        }

        return back();
    }

    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        Gate::authorize('update', $account);

        $account->update($request->validated());

        return back();
    }

    public function destroy(Account $account): RedirectResponse
    {
        Gate::authorize('delete', $account);

        if ($account->postings()->withoutGlobalScope('user')->exists()) {
            throw ValidationException::withMessages([
                'account' => 'This account has transactions. Archive it instead of deleting.',
            ]);
        }

        $account->delete();

        return back();
    }

    /**
     * Seed a new My Account's starting balance against the equity "Opening Balances"
     * account (decision #10) through the single write path. The user enters the
     * human-friendly display value, so the stored amount is flipped back to its raw
     * sign via the type's display sign (asset +, liability −).
     *
     * @param  array<string, mixed>  $data
     */
    private function seedOpeningBalance(User $user, Account $account, array $data): void
    {
        if (blank($data['opening_balance'] ?? null)) {
            return;
        }

        $currency = (string) $account->currency;
        $base = (string) $user->base_currency;

        $nativeMinor = Money::parse($data['opening_balance'], $currency)->minorUnits;
        $baseMinor = $currency === $base
            ? $nativeMinor
            : Money::parse($data['opening_balance_base'], $base)->minorUnits;

        $sign = $account->type->displaySign();
        $equity = Account::query()->where('type', AccountType::Equity->value)->firstOrFail();

        $this->record->create(
            $user,
            TransactionKind::Transfer,
            now()->toDateString(),
            [
                new PostingInput((int) $account->id, $sign * $nativeMinor, $currency, $sign * $baseMinor),
                new PostingInput((int) $equity->id, -$sign * $baseMinor, $base, -$sign * $baseMinor),
            ],
            payee: 'Opening balance',
        );
    }

    /**
     * @return array{
     *     id: int, name: string, type: string, currency: string, archived: bool,
     *     parent_id: int|null, icon: string|null, color: string|null, balance_display: string
     * }
     */
    private function present(Account $account, string $base): array
    {
        $currency = $account->currency ?? $base;
        $balanceMinor = (int) $account->getAttribute('balance_minor');
        // Flip to the human-friendly sign per account type (decision #3).
        $displayMinor = $balanceMinor * $account->type->displaySign();

        return [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'currency' => $currency,
            'archived' => $account->archived,
            'parent_id' => $account->parent_id,
            'icon' => $account->icon,
            'color' => $account->color,
            'balance_display' => Money::ofMinor($displayMinor, $currency)->format(),
        ];
    }
}
