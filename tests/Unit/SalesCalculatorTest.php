<?php

namespace Tests\Unit;

use App\Services\SalesCalculator;
use PHPUnit\Framework\TestCase;

class SalesCalculatorTest extends TestCase
{
    public function test_discount_is_applied_before_tax_with_decimal_precision(): void
    {
        $totals = (new SalesCalculator)->calculate([
            ['quantity' => 2, 'unit_price' => 1000],
            ['quantity' => 3, 'unit_price' => 500],
        ], 10, 15);

        $this->assertSame('3500.00', $totals['subtotal']);
        $this->assertSame('350.00', $totals['discount']);
        $this->assertSame('3150.00', $totals['tax_base']);
        $this->assertSame('472.50', $totals['tax']);
        $this->assertSame('3622.50', $totals['total']);
    }
}
