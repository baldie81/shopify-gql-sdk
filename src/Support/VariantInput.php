<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * Fluent builder for a `ProductVariantsBulkInput` (variant update). Targets an
 * existing variant by id; price-ish fields sit on the variant, while
 * sku/barcode/tracked/cost live under `inventoryItem`.
 *
 *   VariantInput::make($variantId)->price(19.90)->compareAtPrice(24.00)->tracked();
 */
final class VariantInput implements Arrayable
{
    /** @var array<string,mixed> */
    private array $fields = [];

    /** @var array<string,mixed> */
    private array $inventoryItem = [];

    public static function make(string $variantId): self
    {
        $input = new self();
        $input->fields['id'] = $variantId;

        return $input;
    }

    public function price(string|float $price): self
    {
        $this->fields['price'] = self::money($price);

        return $this;
    }

    public function compareAtPrice(string|float $price): self
    {
        $this->fields['compareAtPrice'] = self::money($price);

        return $this;
    }

    public function sku(string $sku): self
    {
        $this->inventoryItem['sku'] = $sku;

        return $this;
    }

    public function barcode(string $barcode): self
    {
        $this->inventoryItem['barcode'] = $barcode;

        return $this;
    }

    /**
     * Toggle inventory tracking (required before quantities can be set).
     */
    public function tracked(bool $tracked = true): self
    {
        $this->inventoryItem['tracked'] = $tracked;

        return $this;
    }

    public function cost(string|float $cost): self
    {
        $this->inventoryItem['cost'] = self::money($cost);

        return $this;
    }

    public function set(string $field, mixed $value): self
    {
        $this->fields[$field] = $value;

        return $this;
    }

    public function toArray(): array
    {
        $input = $this->fields;
        if ($this->inventoryItem !== []) {
            $input['inventoryItem'] = $this->inventoryItem;
        }

        return $input;
    }

    private static function money(string|float $value): string
    {
        return is_string($value) ? $value : number_format($value, 2, '.', '');
    }
}
