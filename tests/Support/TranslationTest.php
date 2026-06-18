<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Enum\ProductTranslatableField;
use Baldie81\ShopifyGraphQL\Support\Translation;
use PHPUnit\Framework\TestCase;

final class TranslationTest extends TestCase
{
    public function testMakeWithEnumKeyUsesShopifyKey(): void
    {
        // Description maps to Shopify's `body_html`, not `descriptionHtml`.
        $t = Translation::make('fr', ProductTranslatableField::Description, '<p>Bonjour</p>');

        self::assertSame('body_html', $t->key());
        self::assertFalse($t->hasDigest());
        self::assertSame([
            'locale' => 'fr',
            'key'    => 'body_html',
            'value'  => '<p>Bonjour</p>',
        ], $t->toArray());
    }

    public function testWithDigestAndForMarket(): void
    {
        $t = Translation::make('es', 'title', 'Sombrero')
            ->withDigest('DIGEST123')
            ->forMarket('gid://shopify/Market/7');

        self::assertTrue($t->hasDigest());
        self::assertSame([
            'locale'                    => 'es',
            'key'                       => 'title',
            'value'                     => 'Sombrero',
            'translatableContentDigest' => 'DIGEST123',
            'marketId'                  => 'gid://shopify/Market/7',
        ], $t->toArray());
    }
}
