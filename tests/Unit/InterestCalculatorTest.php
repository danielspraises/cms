<?php

namespace Tests\Unit;

use App\Models\LoanProduct;
use App\Services\InterestCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class InterestCalculatorTest extends TestCase
{
    private InterestCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new InterestCalculator();
    }

    private function product(array $bands): LoanProduct
    {
        return new LoanProduct(['max_tenure_months' => 12, 'interest_bands' => $bands]);
    }

    private function threeBands(): array
    {
        return [
            ['from_months' => 1, 'to_months' => 3, 'rate_bp' => 500],
            ['from_months' => 4, 'to_months' => 6, 'rate_bp' => 800],
            ['from_months' => 7, 'to_months' => 12, 'rate_bp' => 1200],
        ];
    }

    private function assertRejected(array $bands, ?int $max, string $messagePart): void
    {
        try {
            $this->calculator->normalizeBands($bands, $max);
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());
            return;
        }

        $this->fail('Expected these bands to be rejected.');
    }

    public function test_the_rate_comes_from_the_band_that_matches_the_tenure(): void
    {
        $product = $this->product($this->threeBands());

        $this->assertSame(500, $this->calculator->rateBpFor($product, 2));
        $this->assertSame(800, $this->calculator->rateBpFor($product, 4));
        $this->assertSame(1200, $this->calculator->rateBpFor($product, 12));
        $this->assertNull($this->calculator->rateBpFor($product, 13));
    }

    public function test_interest_is_simple_interest_principal_times_rate_times_months_over_1200(): void
    {
        $product = $this->product($this->threeBands());

        // 1,000,000.00 for 3 months at 5% a year: 1,000,000 x 5 x 3 / 1200 = 12,500.00
        $this->assertSame(12_500_00, $this->calculator->interestFor($product, 1_000_000_00, 3));
        // 5 months falls in the 8% band: 1,000,000 x 8 x 5 / 1200 = 33,333.33
        $this->assertSame(33_333_33, $this->calculator->interestFor($product, 1_000_000_00, 5));
        // A full year at 12% a year is 12% of the loan: 120,000.00
        $this->assertSame(120_000_00, $this->calculator->interestFor($product, 1_000_000_00, 12));
    }

    public function test_interest_rounds_to_the_nearest_kobo(): void
    {
        $product = $this->product([['from_months' => 1, 'to_months' => 12, 'rate_bp' => 1000]]);

        // 100,000.00 at 10% a year for 1 month is 833.333..., which becomes 833.33.
        $this->assertSame(833_33, $this->calculator->interestFor($product, 100_000_00, 1));
    }

    public function test_exactly_half_a_kobo_rounds_up(): void
    {
        $product = $this->product([['from_months' => 1, 'to_months' => 12, 'rate_bp' => 500]]);

        // 1.20 at 5% a year for 1 month is exactly half a kobo, so it rounds up to 1 kobo.
        $this->assertSame(1, $this->calculator->interestFor($product, 120, 1));
        // 1.19 is just under half a kobo, so it rounds down to nothing.
        $this->assertSame(0, $this->calculator->interestFor($product, 119, 1));
    }

    public function test_a_loan_type_with_no_bands_charges_no_interest(): void
    {
        $this->assertSame(0, $this->calculator->interestFor($this->product([]), 1_000_000_00, 6));
    }

    public function test_valid_bands_are_sorted_and_percentages_become_basis_points(): void
    {
        $bands = $this->calculator->normalizeBands([
            ['from_months' => 4, 'to_months' => 12, 'rate_percent' => 7.5],
            ['from_months' => 1, 'to_months' => 3, 'rate_percent' => 5],
        ], 12);

        $this->assertSame([
            ['from_months' => 1, 'to_months' => 3, 'rate_bp' => 500],
            ['from_months' => 4, 'to_months' => 12, 'rate_bp' => 750],
        ], $bands);
    }

    public function test_no_bands_is_always_valid(): void
    {
        $this->assertSame([], $this->calculator->normalizeBands([], null));
    }

    public function test_bands_with_a_gap_are_rejected(): void
    {
        $this->assertRejected([['from_months' => 1, 'to_months' => 3, 'rate_percent' => 5]], 6, 'No band covers months 4 to 6');
    }

    public function test_overlapping_bands_are_rejected(): void
    {
        $this->assertRejected([
            ['from_months' => 1, 'to_months' => 4, 'rate_percent' => 5],
            ['from_months' => 3, 'to_months' => 6, 'rate_percent' => 8],
        ], 6, 'overlap');
    }

    public function test_bands_running_past_the_maximum_tenure_are_rejected(): void
    {
        $this->assertRejected([['from_months' => 1, 'to_months' => 24, 'rate_percent' => 5]], 12, 'past the maximum');
    }

    public function test_bands_need_a_maximum_tenure(): void
    {
        $this->assertRejected([['from_months' => 1, 'to_months' => 6, 'rate_percent' => 5]], null, 'maximum tenure');
    }

    public function test_a_band_cannot_end_before_it_starts(): void
    {
        $this->assertRejected([['from_months' => 5, 'to_months' => 3, 'rate_percent' => 5]], 6, 'cannot end before it starts');
    }
}