<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Enum\ProductTranslatableField;
use Baldie81\ShopifyGraphQL\Support\ProductField;
use PHPUnit\Framework\TestCase;

final class ProductFieldTest extends TestCase
{
    public function testNamedConstructorsMapToShopifyKeys(): void
    {
        self::assertSame('title', ProductField::title()->key());
        self::assertSame('body_html', ProductField::description()->key()); // not "descriptionHtml"
        self::assertSame('handle', ProductField::handle()->key());
        self::assertSame('product_type', ProductField::productType()->key());
        self::assertSame('meta_title', ProductField::seoTitle()->key());
        self::assertSame('meta_description', ProductField::seoDescription()->key());
    }

    public function testOfAcceptsEnumOrRawString(): void
    {
        self::assertSame('body_html', ProductField::of(ProductTranslatableField::Description)->key());
        self::assertSame('custom_key', ProductField::of('custom_key')->key());
    }

    public function testCarriesBaseValueAndInputKey(): void
    {
        $field = ProductField::description('<p>en</p>')->withTranslation('fr', '<p>fr</p>');

        self::assertSame('<p>en</p>', $field->value());
        self::assertSame('descriptionHtml', $field->inputKey()); // GraphQL input key
        self::assertSame('body_html', $field->key());            // translation key
        self::assertSame('seo.title', ProductField::seoTitle('X')->inputKey());
        self::assertNull(ProductField::title()->value());
    }

    public function testWithTranslationAccumulates(): void
    {
        $field = ProductField::title()
            ->withTranslation('fr', 'Robe')
            ->withTranslation('es', 'Vestido');

        self::assertSame([
            ['locale' => 'fr', 'value' => 'Robe'],
            ['locale' => 'es', 'value' => 'Vestido'],
        ], $field->translations());
    }
}
