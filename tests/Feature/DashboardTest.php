<?php

use App\Actions\Transactions\PostingInput;
use App\Actions\Transactions\RecordTransaction;
use App\Enums\TransactionKind;
use App\Models\Account;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the dashboard shows net worth and account balances', function () {
    $user = User::factory()->onboarded('PEN')->create();
    $this->actingAs($user);

    $checking = Account::factory()->asset('PEN')->for($user)->create(['name' => 'Checking']);
    $visa = Account::factory()->liability('PEN')->for($user)->create(['name' => 'Visa']);
    $opening = Account::factory()->equity()->for($user)->create();
    $groceries = Account::factory()->expense()->for($user)->create();
    $record = app(RecordTransaction::class);

    // Seed S/1000 into checking, then spend S/50 on the Visa → net worth S/950.
    $record->create($user, TransactionKind::Transfer, '2026-06-01', [
        new PostingInput($checking->id, 100000, 'PEN'),
        new PostingInput($opening->id, -100000, 'PEN'),
    ]);
    $record->create($user, TransactionKind::Expense, '2026-06-05', [
        new PostingInput($visa->id, -5000, 'PEN'),
        new PostingInput($groceries->id, 5000, 'PEN'),
    ]);

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->has('netWorth', 1)
        ->where('netWorth.0.currency', 'PEN')
        ->where('netWorth.0.display', "PEN\u{00A0}950.00")
        ->has('assets', 1)
        ->has('liabilities', 1)
        ->where('assets.0.balance_display', "PEN\u{00A0}1,000.00")
        ->where('liabilities.0.balance_display', "PEN\u{00A0}50.00") // display-signed: owed reads positive
    );
});
