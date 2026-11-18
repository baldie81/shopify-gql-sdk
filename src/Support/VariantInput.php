<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * Fluent builder for a `ProductVariantsBulkInput`. Price-ish fields sit on the
 * variant, while sku/tracked/cost live under `inventoryItem`.
 *
 *   // update: target an existing variant (numeric id or GID)
 *   VariantInput::make($variantId)->price('19.90')->compareAtPrice('24.00')->tracked();
 *
 *   // create: no id; say which option values the new variant has
 *   VariantInput::new()->optionValue('Size', 'XL')->price('19.90')->sku('TEE-XL');
 */
final class VariantInput implements Arrayable
{
    /** @var array<string,mixed> */
    private array $fields = [];

    /** @var array<string,mixed> */
    private array $inventoryItem = [];

    /** @var list<array<string,string>> */
    private array $optionValues = [];

    /**
     * Target an existing variant (for {@see \Baldie81\ShopifyGraphQL\Resource\Variants::update()}).
     * Accepts a numeric id or a GID. Pass null — or use {@see new()} — for a
     * variant that doesn't exist yet.
     */
    public static function make(?string $variantId = null): self
    {
        $input = new self();
        if ($variantId !== null) {
            $input->fields['id'] = Gid::variant($variantId);
        }

        return $input;
    }

    /**
     * A variant to be created (for {@see \Baldie81\ShopifyGraphQL\Resource\Variants::create()}): no id.
     */
    public static function new(): self
    {
        return new self();
    }

    /**
     * The value this variant takes for one of the product's options, e.g.
     * `optionValue('Size', 'XL')`. Call once per option.
     */
    public function optionValue(string $optionName, string $value): self
    {
        $this->optionValues[] = ['optionName' => $optionName, 'name' => $value];

        return $this;
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
        // A variant-level field (not part of `inventoryItem`).
        $this->fields['barcode'] = $barcode;

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
        if ($this->optionValues !== []) {
            $input['optionValues'] = $this->optionValues;
        }
        if ($this->inventoryItem !== []) {
            $input['inventoryItem'] = $this->inventoryItem;
        }

        return $input;
    }

    private static function money(string|float $value): string
    {
        return Money::decimal($value);
    }
}
