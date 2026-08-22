type DecimalParts = {
    integer: bigint;
    scale: number;
};

export type InvoiceCalculationLine = {
    quantity: string;
    unit_price: string;
};

function decimalParts(value: string): DecimalParts {
    const normalized = value.trim();
    const match = /^(\d+)(?:\.(\d*))?$/u.exec(normalized);

    if (!match) {
        return { integer: 0n, scale: 0 };
    }

    const fraction = match[2] ?? '';

    return {
        integer: BigInt(`${match[1]}${fraction}`),
        scale: fraction.length,
    };
}

function rescale(integer: bigint, fromScale: number, toScale: number) {
    if (fromScale === toScale) {
        return integer;
    }

    const factor = 10n ** BigInt(Math.abs(toScale - fromScale));

    return fromScale < toScale ? integer * factor : integer / factor;
}

function multiplyAtScale(left: string, right: string, scale: number) {
    const first = decimalParts(left || '0');
    const second = decimalParts(right || '0');

    return rescale(
        first.integer * second.integer,
        first.scale + second.scale,
        scale,
    );
}

function rateAtScale(value: string) {
    const rate = decimalParts(value || '0');

    return rescale(rate.integer, rate.scale, 2);
}

function moneyNumber(valueAtFourDecimals: bigint) {
    return Number(valueAtFourDecimals / 100n) / 100;
}

export function calculateInvoiceLineTotal(quantity: string, unitPrice: string) {
    return moneyNumber(multiplyAtScale(quantity, unitPrice, 4));
}

export function truncateInvoiceMoney(value: string) {
    const decimal = decimalParts(value || '0');

    return Number(rescale(decimal.integer, decimal.scale, 2)) / 100;
}

export function calculateInvoiceTotals(
    items: InvoiceCalculationLine[],
    discountRate: string,
    taxRate: string,
) {
    const subtotal = items.reduce(
        (sum, item) => sum + multiplyAtScale(item.quantity, item.unit_price, 4),
        0n,
    );
    const discount = (subtotal * rateAtScale(discountRate || '0')) / 10_000n;
    const taxBase = subtotal - discount;
    const tax = (taxBase * rateAtScale(taxRate || '0')) / 10_000n;

    return {
        subtotal: moneyNumber(subtotal),
        discount: moneyNumber(discount),
        tax: moneyNumber(tax),
        total: moneyNumber(taxBase + tax),
    };
}
