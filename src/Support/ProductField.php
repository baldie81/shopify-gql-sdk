<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

use Baldie81\ShopifyGraphQL\Enum\ProductTranslatableField;

/**
 * A translatable product field carrying both its base (default-locale) value and
 * its translations, fluently:
 *
 *   ProductField::title('A nice t-shirt')->withTranslation('fr', 'Un beau t-shirt');
 *   ProductField::description('<p>en</p>')->withTranslation('fr', '<p>fr</p>');
 *
 * Attach these to a {@see ProductInput} via {@see ProductInput::field()} and the
 * base values populate the create/update input while the translations are
 * registered automatically once the product exists. The named constructors map
 * each field to its GraphQL input key (e.g. `descriptionHtml`) and its Shopify
 * translation key (e.g. `body_html`), which differ.
 */
final class ProductField
{
    /** @var list<array{locale:string,value:string}> */
    private array $translations = [];

    private function __construct(
        private readonly string $translationKey,
        private readonly string $inputKey,
        private readonly ?string $value,
    ) {}

    public static function of(ProductTranslatableField|string $key, ?string $value = null): self
    {
        $key = $key instanceof ProductTranslatableField ? $key->value : $key;

        return new self($key, $key, $value);
    }

    public static function title(?string $value = null): self
    {
        return new self('title', 'title', $value);
    }

    public static function description(?string $value = null): self
    {
        return new self('body_html', 'descriptionHtml', $value);
    }

    public static function handle(?string $value = null): self
    {
        return new self('handle', 'handle', $value);
    }

    public static function productType(?string $value = null): self
    {
        return new self('product_type', 'productType', $value);
    }

    public static function seoTitle(?string $value = null): self
    {
        return new self('meta_title', 'seo.title', $value);
    }

    public static function seoDescription(?string $value = null): self
    {
        return new self('meta_description', 'seo.description', $value);
    }

    /**
     * Add a translation of this field for the given locale.
     */
    public function withTranslation(string $locale, string $value): self
    {
        $this->translations[] = ['locale' => $locale, 'value' => $value];

        return $this;
    }

    /**
     * The Shopify translation key (e.g. `body_html`).
     */
    public function key(): string
    {
        return $this->translationKey;
    }

    /**
     * The `ProductInput` field path for the base value (e.g. `descriptionHtml`,
     * or dotted `seo.title`).
     */
    public function inputKey(): string
    {
        return $this->inputKey;
    }

    /**
     * The base (default-locale) value, if set.
     */
    public function value(): ?string
    {
        return $this->value;
    }

    /**
     * @return list<array{locale:string,value:string}>
     */
    public function translations(): array
    {
        return $this->translations;
    }
}
