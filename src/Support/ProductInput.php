<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

use Baldie81\ShopifyGraphQL\Enum\ProductStatus;

/**
 * Fluent builder for a `ProductCreateInput` / `ProductUpdateInput` value (the
 * inner `product` variable — the resource adds the `id` for updates). Unset
 * fields are omitted, so the same builder serves create and partial update.
 *
 *   ProductInput::make('Summer Hat')
 *       ->descriptionHtml('<p>Straw hat</p>')
 *       ->vendor('Acme')->productType('Hat')->handle('summer-hat')
 *       ->status(ProductStatus::Draft)->tags(['summer', 'sale']);
 *
 * Variants are intentionally out of scope here (Shopify moved them out of the
 * product input into productVariantsBulkCreate from 2024-10).
 */
final class ProductInput implements Arrayable
{
    /** @var array<string,mixed> */
    private array $fields = [];

    /** @var list<array<string,mixed>> */
    private array $metafields = [];

    /**
     * Title is required to create a product, optional when updating; pass null
     * to build an update that doesn't change it.
     */
    public static function make(?string $title = null): self
    {
        $input = new self();
        if ($title !== null) {
            $input->fields['title'] = $title;
        }

        return $input;
    }

    public function title(string $title): self
    {
        $this->fields['title'] = $title;

        return $this;
    }

    public function descriptionHtml(string $descriptionHtml): self
    {
        $this->fields['descriptionHtml'] = $descriptionHtml;

        return $this;
    }

    public function vendor(string $vendor): self
    {
        $this->fields['vendor'] = $vendor;

        return $this;
    }

    public function productType(string $productType): self
    {
        $this->fields['productType'] = $productType;

        return $this;
    }

    public function handle(string $handle): self
    {
        $this->fields['handle'] = $handle;

        return $this;
    }

    public function status(ProductStatus|string $status): self
    {
        $this->fields['status'] = $status instanceof ProductStatus ? $status->value : $status;

        return $this;
    }

    /**
     * @param list<string> $tags
     */
    public function tags(array $tags): self
    {
        $this->fields['tags'] = array_values($tags);

        return $this;
    }

    /**
     * Attach a metafield to set as part of the create/update (upserted by
     * namespace/key for the product). Accepts a {@see Metafield} builder or a
     * raw array.
     *
     * @param Metafield|array<string,mixed> $metafield
     */
    public function metafield(Metafield|array $metafield): self
    {
        $this->metafields[] = $metafield instanceof Arrayable ? $metafield->toArray() : $metafield;

        return $this;
    }

    /**
     * @param list<Metafield|array<string,mixed>> $metafields
     */
    public function metafields(array $metafields): self
    {
        foreach ($metafields as $metafield) {
            $this->metafield($metafield);
        }

        return $this;
    }

    /**
     * Escape hatch for any input field not modelled above (e.g. `seo`, `giftCard`).
     */
    public function set(string $field, mixed $value): self
    {
        $this->fields[$field] = $value;

        return $this;
    }

    public function toArray(): array
    {
        $input = $this->fields;

        if ($this->metafields !== []) {
            $input['metafields'] = $this->metafields;
        }

        return $input;
    }
}
