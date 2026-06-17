<?php

namespace App\Http\Controllers;

use App\Actions\ProvisionNewUserLedger;
use App\Http\Requests\OnboardingRequest;
use App\Support\Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    /**
     * Show the base-currency picker, or skip to the dashboard if already onboarded.
     */
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->user()->base_currency !== null) {
            return to_route('dashboard');
        }

        return Inertia::render('Onboarding', [
            'currencies' => Currencies::options(),
        ]);
    }

    /**
     * Set the base currency (once, immutably) and seed the starter ledger.
     */
    public function store(OnboardingRequest $request, ProvisionNewUserLedger $provision): RedirectResponse
    {
        $user = $request->user();

        // Base currency is immutable once set (decision #9); ignore re-submits.
        if ($user->base_currency === null) {
            $user->base_currency = strtoupper($request->validated()['base_currency']);
            $user->save();

            $provision->provision($user);
        }

        return to_route('dashboard');
    }
}
