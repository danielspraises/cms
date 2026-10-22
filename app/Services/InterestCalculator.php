<?php

namespace App\Services;

use App\Models\LoanProduct;
use InvalidArgumentException;

/**
 * Interest is simple interest: principal x annual rate x months / 1200, with
 * the annual rate chosen by the tenure band the loan falls in. Money is
 * integer kobo throughout; rates are stored as basis points per year
 * (500 = 5.00% a year).
 */
class InterestCalculator
{
    /** The annual rate in basis points for this tenure, or null if no band covers it. */
    public function rateBpFor(LoanProduct $product, int $tenureMonths): ?int
    {
        foreach ($product->interest_bands ?? [] as $band) {
            if ($tenureMonths >= (int) $band['from_months'] && $tenureMonths <= (int) $band['to_months']) {
                return (int) $band['rate_bp'];
            }
        }

        return null;
    }

    /**
     * Total interest in kobo for the whole term: principal x rate x months / 1200.
     * The rate is held in basis points, so the divisor is 1200 x 100 = 120000.
     * Rounded half up to the nearest kobo.
     */
    public function interestFor(LoanProduct $product, int $principalMinor, int $tenureMonths): int
    {
        $rateBp = $this->rateBpFor($product, $tenureMonths);

        if ($rateBp === null || $rateBp <= 0 || $principalMinor <= 0 || $tenureMonths <= 0) {
            return 0;
        }

        return intdiv($principalMinor * $rateBp * $tenureMonths + 60000, 120000);
    }

    /**
     * Validates a set of bands entered as annual percentages and returns them
     * stored as basis points. Bands must cover every month from 1 to the
     * maximum tenure with no gaps or overlaps, so no tenure can silently have
     * no rate.
     *
     * @param  array<int, array{from_months:int|string, to_months:int|string, rate_percent:int|float|string}>  $bands
     * @throws InvalidArgumentException
     */
    public function normalizeBands(array $bands, ?int $maxTenureMonths): array
    {
        if (count($bands) === 0) {
            return [];
        }

        if ($maxTenureMonths === null) {
            throw new InvalidArgumentException('Set a maximum tenure before adding interest bands.');
        }

        usort($bands, fn (array $a, array $b) => (int) $a['from_months'] <=> (int) $b['from_months']);

        $normalized = [];
        $expectedFrom = 1;

        foreach ($bands as $band) {
            $from = (int) $band['from_months'];
            $to = (int) $band['to_months'];

            if ($to < $from) {
                throw new InvalidArgumentException("A band cannot end before it starts ({$from} to {$to} months).");
            }
            if ($from < $expectedFrom) {
                throw new InvalidArgumentException("Interest bands overlap around month {$from}.");
            }
            if ($from > $expectedFrom) {
                $gapEnd = $from - 1;
                throw new InvalidArgumentException("No band covers months {$expectedFrom} to {$gapEnd}.");
            }

            $normalized[] = [
                'from_months' => $from,
                'to_months' => $to,
                'rate_bp' => (int) round(((float) $band['rate_percent']) * 100),
            ];
            $expectedFrom = $to + 1;
        }

        $lastCovered = $expectedFrom - 1;
        if ($lastCovered < $maxTenureMonths) {
            throw new InvalidArgumentException("No band covers months {$expectedFrom} to {$maxTenureMonths}.");
        }
        if ($lastCovered > $maxTenureMonths) {
            throw new InvalidArgumentException("The last band runs past the maximum tenure of {$maxTenureMonths} months.");
        }

        return $normalized;
    }
}