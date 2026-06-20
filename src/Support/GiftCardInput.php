<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * Fluent builder for a `GiftCardCreateInput`.
 *
 *   GiftCardInput::make('50.00')->customer($customerId)->note('Loyalty reward')->expiresOn('2027-12-31');
 *
 * `initialValue` is a decimal amount in the shop currency. Omit `code()` to let
 * Shopify generate one — and remember the plaintext code is only returned once,
 * from `giftCardCreate` (as `giftCardCode`).
 */
final class GiftCardInput implements Arrayable
{
    /** @var array<string,mixed> */
    private array $fields = [];

    public static function make(string|float $initialValue): self
    {
        $input = new self();
        $input->fields['initialValue'] = self::amount($initialValue);

        return $input;
    }

    public function initialValue(string|float $initialValue): self
    {
        $this->fields['initialValue'] = self::amount($initialValue);

        return $this;
    }

    /**
     * A custom code (8–20 alphanumeric chars). Omit to auto-generate.
     */
    public function code(string $code): self
    {
        $this->fields['code'] = $code;

        return $this;
    }

    /**
     * Internal note (not shown to the customer).
     */
    public function note(string $note): self
    {
        $this->fields['note'] = $note;

        return $this;
    }

    /**
     * Expiry date (YYYY-MM-DD). Some regions disallow expiry.
     */
    public function expiresOn(string $date): self
    {
        $this->fields['expiresOn'] = $date;

        return $this;
    }

    public function customer(string $customerId): self
    {
        $this->fields['customerId'] = Gid::customer($customerId);

        return $this;
    }

    public function templateSuffix(string $templateSuffix): self
    {
        $this->fields['templateSuffix'] = $templateSuffix;

        return $this;
    }

    public function set(string $field, mixed $value): self
    {
        $this->fields[$field] = $value;

        return $this;
    }

    public function toArray(): array
    {
        return $this->fields;
    }

    private static function amount(string|float $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
