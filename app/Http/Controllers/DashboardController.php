<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\User;
use App\Support\Money;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $base = (string) $user->base_currency;

        $accounts = Account::query()
            ->whereIn('type', [AccountType::Asset->value, AccountType::Liability->value])
            ->where('archived', false)
            ->withSum('postings as balance_minor', 'amount')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account): array => $this->present($account, $base));

        // Net worth as per-currency buckets (decision #15) — not blended into one total.
        $netWorth = collect($user->netWorth())
            ->map(fn (int $minor, string $currency): array => [
                'currency' => $currency,
                'display' => Money::ofMinor($minor, $currency)->format(),
            ])
            ->values();

        return Inertia::render('Dashboard', [
            'netWorth' => $netWorth,
            'assets' => $accounts->where('type', AccountType::Asset->value)->values(),
            'liabilities' => $accounts->where('type', AccountType::Liability->value)->values(),
            'baseCurrency' => $base,
        ]);
    }

    /**
     * @return array{id: int, name: string, type: string, currency: string, balance_display: string}
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
            'balance_display' => Money::ofMinor($displayMinor, $currency)->format(),
        ];
    }
}
