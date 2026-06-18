<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

use Baldie81\ShopifyGraphQL\Enum\ProductTranslatableField;

/**
 * A translatable product field with its translations attached fluently, for
 * {@see \Baldie81\ShopifyGraphQL\Resource\Products::setTranslations()}.
 *
 * The named constructors hide Shopify's internal keys (e.g. the description is
 * `body_html`), so you work in product terms:
 *
 *   ProductField::title()->withTranslation('fr', 'Robe')->withTranslation('es', 'Vestido');
 *   ProductField::description()->withTranslation('fr', '<p>Une robe</p>');
 */
final class ProductField
{
    /** @var list<array{locale:string,value:string}> */
    private array $translations = [];

    private function __construct(
        private readonly string $key,
    ) {}

    public static function of(ProductTranslatableField|string $key): self
    {
        return new self($key instanceof ProductTranslatableField ? $key->value : $key);
    }

    public static function title(): self
    {
        return new self(ProductTranslatableField::Title->value);
    }

    public static function description(): self
    {
        return new self(ProductTranslatableField::Description->value);
    }

    public static function handle(): self
    {
        return new self(ProductTranslatableField::Handle->value);
    }

    public static function productType(): self
    {
        return new self(ProductTranslatableField::ProductType->value);
    }

    public static function seoTitle(): self
    {
        return new self(ProductTranslatableField::SeoTitle->value);
    }

    public static function seoDescription(): self
    {
        return new self(ProductTranslatableField::SeoDescription->value);
    }

    /**
     * Add a translation of this field for the given locale.
     */
    public function withTranslation(string $locale, string $value): self
    {
        $this->translations[] = ['locale' => $locale, 'value' => $value];

        return $this;
    }

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return list<array{locale:string,value:string}>
     */
    public function translations(): array
    {
        return $this->translations;
    }
}
