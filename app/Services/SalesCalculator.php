<?php

namespace App\Services;

class SalesCalculator
{
    /**
     * @param  array<int, array{quantity: int|float|string, unit_price: int|float|string}>  $items
     * @return array{subtotal: string, discount: string, tax_base: string, tax: string, total: string}
     */
    public function calculate(array $items, int|float|string $discountRate = 0, int|float|string $taxRate = 0): array
    {
        $subtotal = '0.0000';

        foreach ($items as $item) {
            $lineTotal = bcmul($this->numeric($item['quantity']), $this->numeric($item['unit_price']), 4);
            $subtotal = bcadd($subtotal, $lineTotal, 4);
        }

        $discount = bcdiv(bcmul($subtotal, $this->numeric($discountRate), 6), '100', 4);
        $taxBase = bcsub($subtotal, $discount, 4);
        $tax = bcdiv(bcmul($taxBase, $this->numeric($taxRate), 6), '100', 4);
        $total = bcadd($taxBase, $tax, 4);

        return [
            'subtotal' => $this->money($subtotal),
            'discount' => $this->money($discount),
            'tax_base' => $this->money($taxBase),
            'tax' => $this->money($tax),
            'total' => $this->money($total),
        ];
    }

    /** @param numeric-string $value */
    private function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }

    /** @return numeric-string */
    private function numeric(int|float|string $value): string
    {
        $numeric = (string) $value;
        if (! is_numeric($numeric)) {
            throw new \InvalidArgumentException('Sales values must be numeric.');
        }

        return $numeric;
    }
}
