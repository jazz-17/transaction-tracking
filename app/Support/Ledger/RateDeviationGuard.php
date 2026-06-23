<?php

namespace App\Support\Ledger;

use App\Actions\Transactions\PostingInput;
use App\Models\Posting;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;

/**
 * The soft protection an exchange actually needs (decision #11).
 *
 * Because a cross-currency transaction is two observed amounts with no stored rate, no hard
 * check can catch a fat-finger (a `$30` / `$3,000` slip, a missing or extra zero). This guard
 * compares the implied rate of the entry — `−B/F` over its money legs — against the user's
 * **last** rate for the same base↔foreign pair and *warns* when they diverge by more than a
 * generous factor. It never blocks: the caller surfaces the warning and the user confirms.
 * The first exchange for a pair sets the baseline (no warning).
 */
final class RateDeviationGuard
{
    /**
     * The deviation band, as a multiplicative factor: an entry is flagged when its implied
     * rate is ≥ FACTOR× or ≤ 1/FACTOR× the baseline — a symmetric one-octave window.
     *
     * This is a typo *tripwire*, not a rate estimator, so the band is deliberately wide and
     * the baseline is the single most recent rate ({@see lastRate()}), not an average. The
     * width is what keeps that noisy one-observation baseline usable: it stays quiet on real
     * market drift (which moves far less than 2× between a user's own consecutive entries)
     * while still catching the order-of-magnitude slips it exists for — a missing/extra zero.
     *
     * The trade-off is two intentional blind spots: a sub-2× slip (e.g. a near-double digit
     * transposition) and any proportional both-leg error (the rate is scale-invariant) pass
     * silently. Both are out of scope for a warn-and-confirm guard, and no baseline statistic
     * — mean, median, EWMA — would catch them; a tighter band would only trade them for false
     * warnings against the noisy baseline.
     */
    public const FACTOR = 2.0;

    /**
     * Return a human warning when the composed postings are a base↔foreign exchange whose
     * implied rate deviates beyond {@see FACTOR}× from the user's last rate for that pair;
     * otherwise `null`. `$excludeTransactionId` skips the transaction being edited.
     *
     * @param  array<int, PostingInput>  $postings
     */
    public function warn(User $user, string $baseCurrency, array $postings, ?int $excludeTransactionId = null): ?string
    {
        $base = strtoupper($baseCurrency);

        $current = $this->impliedRate(
            $this->sumByCurrency(array_map(
                fn (PostingInput $posting): array => ['amount' => $posting->amount, 'currency' => $posting->currency],
                $postings,
            ), $base),
            $base,
        );

        if ($current === null) {
            return null;
        }

        [$foreign, $currentRate] = $current;

        $last = $this->lastRate($user, $base, $foreign, $excludeTransactionId);
        if ($last === null) {
            return null;
        }

        $ratio = $currentRate / $last;
        if ($ratio < self::FACTOR && $ratio > 1 / self::FACTOR) {
            return null;
        }

        return sprintf(
            'The implied rate (1 %s ≈ %s %s) is far from your last (%s %s). Re-check the amounts, or confirm to record it anyway.',
            $foreign,
            self::trim($currentRate),
            $base,
            self::trim($last),
            $base,
        );
    }

    /**
     * The user's most recent implied rate for each base↔foreign pair, keyed by foreign
     * currency code and formatted as base units per 1 foreign unit (e.g. ['USD' => '3.7']).
     * Pairs with no prior exchange are omitted. Drives the entry form's "last used" reference —
     * the proactive, at-entry complement to {@see warn()}'s on-submit check (decision #11).
     *
     * @param  array<int, string>  $foreignCurrencies
     * @return array<string, string>
     */
    public function lastRatesByForeignCurrency(User $user, string $baseCurrency, array $foreignCurrencies): array
    {
        $base = strtoupper($baseCurrency);
        $rates = [];

        foreach (array_unique(array_map(strtoupper(...), $foreignCurrencies)) as $foreign) {
            if ($foreign === $base) {
                continue;
            }

            $rate = $this->lastRate($user, $base, $foreign, null);
            if ($rate !== null) {
                $rates[$foreign] = self::trim($rate);
            }
        }

        return $rates;
    }

    /**
     * Sum a line set into `['base' => minor, 'foreign' => minor, 'foreignCurrency' => code|null]`.
     * Lines whose currency equals base fold into `base`; everything else into `foreign`.
     *
     * @param  array<int, array{amount: int, currency: string}>  $lines
     * @return array{base: int, foreign: int, foreignCurrency: ?string, foreignCurrencies: int}
     */
    private function sumByCurrency(array $lines, string $base): array
    {
        $baseSum = 0;
        $foreignSum = 0;
        $foreignCurrency = null;
        $foreignCodes = [];

        foreach ($lines as $line) {
            $currency = strtoupper($line['currency']);

            if ($currency === $base) {
                $baseSum += $line['amount'];

                continue;
            }

            $foreignSum += $line['amount'];
            $foreignCurrency = $currency;
            $foreignCodes[$currency] = true;
        }

        return [
            'base' => $baseSum,
            'foreign' => $foreignSum,
            'foreignCurrency' => $foreignCurrency,
            'foreignCurrencies' => count($foreignCodes),
        ];
    }

    /**
     * The exchange's implied rate as `[foreignCurrency, rate]`, or `null` when the summed lines
     * are not a single base↔foreign exchange (single-currency, no base leg, no foreign leg, or
     * — defensively — more than one foreign currency). Exponent-safe via {@see Money::deriveRate}.
     *
     * @param  array{base: int, foreign: int, foreignCurrency: ?string, foreignCurrencies: int}  $sums
     * @return array{0: string, 1: float}|null
     */
    private function impliedRate(array $sums, string $base): ?array
    {
        if ($sums['foreignCurrencies'] !== 1 || $sums['foreignCurrency'] === null) {
            return null;
        }

        if ($sums['base'] === 0 || $sums['foreign'] === 0) {
            return null;
        }

        $rate = (float) Money::deriveRate($sums['base'], $base, $sums['foreign'], $sums['foreignCurrency']);

        return $rate > 0.0 ? [$sums['foreignCurrency'], $rate] : null;
    }

    /**
     * The user's most recent base↔foreign exchange rate, or `null` if they have none yet.
     * The latest transaction touching **both** currencies is, by the invariant (#16), a
     * two-currency swap; its rate is `−B/F` over all its legs (a base-currency fee nets out).
     */
    private function lastRate(User $user, string $base, string $foreign, ?int $excludeTransactionId): ?float
    {
        $transaction = Transaction::query()
            ->where('user_id', $user->getKey())
            ->when($excludeTransactionId !== null, fn ($query) => $query->whereKeyNot($excludeTransactionId))
            ->whereHas('postings', fn ($query) => $query->where('currency', $base))
            ->whereHas('postings', fn ($query) => $query->where('currency', $foreign))
            ->with('postings:id,transaction_id,amount,currency')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->first();

        if ($transaction === null) {
            return null;
        }

        $sums = $this->sumByCurrency(
            $transaction->postings
                ->map(fn (Posting $posting): array => ['amount' => (int) $posting->amount, 'currency' => $posting->currency])
                ->all(),
            $base,
        );

        return $this->impliedRate($sums, $base)[1] ?? null;
    }

    private static function trim(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.');
    }
}
