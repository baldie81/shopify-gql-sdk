<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * Formats amounts for Shopify's `Decimal` / `Money` scalars.
 *
 * Pass amounts as **strings** whenever precision matters: a numeric string is
 * sent digit-for-digit, never rounded (so `'10.005'` stays three decimals for
 * KWD/BHD/…; `'40'` is merely padded to `'40.00'`).
 * Floats are a convenience — rendered with at least two and at most four
 * decimals. Anything that isn't a plain decimal number (`'1,50'`, `'€5'`, `''`)
 * is rejected instead of being silently coerced to a different amount.
 */
final class Money
{
    /**
     * @throws \InvalidArgumentException when a string isn't a plain decimal number
     */
    public static function decimal(string|int|float $amount): string
    {
        if (is_string($amount)) {
            $amount = trim($amount);
            if (preg_match('/^-?\d+(\.\d+)?$/', $amount) !== 1) {
                throw new \InvalidArgumentException(
                    "Invalid amount \"{$amount}\": expected a decimal number such as \"12.50\".",
                );
            }

            // Exact digits are kept; only pad to the conventional two decimals.
            [$whole, $fraction] = explode('.', $amount) + [1 => ''];

            return $whole . '.' . str_pad($fraction, 2, '0');
        }

        if (is_float($amount) && !is_finite($amount)) {
            throw new \InvalidArgumentException('Invalid amount: not a finite number.');
        }

        [$whole, $fraction] = explode('.', number_format((float) $amount, 4, '.', ''));

        return $whole . '.' . str_pad(rtrim($fraction, '0'), 2, '0');
    }
}
