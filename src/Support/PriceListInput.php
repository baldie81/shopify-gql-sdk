<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * Fluent builder for `PriceListCreateInput` / `PriceListUpdateInput` (contextual
 * / market / B2B pricing). A price list is relative to a catalog's context and
 * carries a `parent` adjustment; you can then override variants with fixed prices
 * (see {@see \Baldie81\ShopifyGraphQL\Resource\PriceLists::setPrices()}).
 *
 *   PriceListInput::make('Wholesale EUR')->currency('EUR')->catalogId($catalogId)
 *       ->adjustment('PERCENTAGE_DECREASE', 20);
 */
final class PriceListInput implements Arrayable
{
    /** @var array<string,mixed> */
    private array $fields = [];

    public static function make(?string $name = null): self
    {
        $input = new self();
        if ($name !== null) {
            $input->fields['name'] = $name;
        }

        return $input;
    }

    public function name(string $name): self
    {
        $this->fields['name'] = $name;

        return $this;
    }

    /**
     * The price list currency (a `CurrencyCode`, e.g. "EUR", "USD").
     */
    public function currency(string $currency): self
    {
        $this->fields['currency'] = $currency;

        return $this;
    }

    public function catalogId(string $catalogId): self
    {
        $this->fields['catalogId'] = $catalogId;

        return $this;
    }

    /**
     * Relative pricing vs. the catalog context. `$type` is a
     * `PriceListAdjustmentType` ("PERCENTAGE_INCREASE" / "PERCENTAGE_DECREASE").
     */
    public function adjustment(string $type, float $value): self
    {
        $this->fields['parent'] = ['adjustment' => ['type' => $type, 'value' => $value]];

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
}
