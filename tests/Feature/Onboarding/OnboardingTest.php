<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('redirects un-onboarded users away from the app to onboarding', function () {
    $user = User::factory()->create(['base_currency' => null]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('onboarding.show'));
});

it('lets onboarded users reach the dashboard', function () {
    $user = User::factory()->create(['base_currency' => 'PEN']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

it('skips onboarding for already-onboarded users', function () {
    $user = User::factory()->create(['base_currency' => 'PEN']);

    $this->actingAs($user)
        ->get(route('onboarding.show'))
        ->assertRedirect(route('dashboard'));
});

it('shows the onboarding page with currency options', function () {
    $user = User::factory()->create(['base_currency' => null]);

    $this->actingAs($user)
        ->get(route('onboarding.show'))
        ->assertInertia(fn (Assert $page) => $page->component('Onboarding')->has('currencies'));
});

it('sets the base currency and seeds the ledger on submit', function () {
    $user = User::factory()->create(['base_currency' => null]);

    $this->actingAs($user)
        ->post(route('onboarding.store'), ['base_currency' => 'PEN'])
        ->assertRedirect(route('dashboard'));

    $user->refresh();
    expect($user->base_currency)->toBe('PEN')
        ->and($user->accounts()->count())->toBeGreaterThan(0);
});

it('keeps the base currency immutable once set', function () {
    $user = User::factory()->create(['base_currency' => 'PEN']);

    $this->actingAs($user)->post(route('onboarding.store'), ['base_currency' => 'USD']);

    expect($user->refresh()->base_currency)->toBe('PEN');
});

it('rejects an unsupported base currency', function () {
    $user = User::factory()->create(['base_currency' => null]);

    $this->actingAs($user)
        ->post(route('onboarding.store'), ['base_currency' => 'XYZ'])
        ->assertSessionHasErrors('base_currency');

    expect($user->refresh()->base_currency)->toBeNull();
});
