<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the app behind onboarding: a user who hasn't picked a base currency yet
 * (decision #9) is redirected to the onboarding flow before reaching the ledger.
 */
class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->base_currency === null) {
            return redirect()->route('onboarding.show');
        }

        return $next($request);
    }
}
