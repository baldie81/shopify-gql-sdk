<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Enum\MetafieldType;
use Baldie81\ShopifyGraphQL\Enum\ProductStatus;
use Baldie81\ShopifyGraphQL\Support\Arrayable;
use Baldie81\ShopifyGraphQL\Support\Metafield;
use Baldie81\ShopifyGraphQL\Support\ProductField;
use Baldie81\ShopifyGraphQL\Support\ProductInput;
use PHPUnit\Framework\TestCase;

final class ProductInputTest extends TestCase
{
    public function testBuildsInputAndOmitsUnsetFields(): void
    {
        $input = ProductInput::make('Summer Hat')
            ->descriptionHtml('<p>Straw</p>')
            ->vendor('Acme')
            ->productType('Hat')
            ->handle('summer-hat')
            ->status(ProductStatus::Draft)
            ->tags(['summer', 'sale']);

        self::assertInstanceOf(Arrayable::class, $input);
        self::assertSame([
            'title'           => 'Summer Hat',
            'descriptionHtml' => '<p>Straw</p>',
            'vendor'          => 'Acme',
            'productType'     => 'Hat',
            'handle'          => 'summer-hat',
            'status'          => 'DRAFT',
            'tags'            => ['summer', 'sale'],
        ], $input->toArray());
    }

    public function testMakeWithoutTitleOmitsTitle(): void
    {
        self::assertSame(
            ['status' => 'ACTIVE'],
            ProductInput::make()->status(ProductStatus::Active)->toArray(),
        );
    }

    public function testStatusAcceptsEnumOrString(): void
    {
        self::assertSame('ARCHIVED', ProductInput::make()->status(ProductStatus::Archived)->toArray()['status']);
        self::assertSame('ACTIVE', ProductInput::make()->status('ACTIVE')->toArray()['status']);
    }

    public function testSetEscapeHatch(): void
    {
        self::assertSame(
            ['title' => 'X', 'giftCard' => true],
            ProductInput::make('X')->set('giftCard', true)->toArray(),
        );
    }

    public function testFieldSetsBaseValuesIncludingNestedSeoAndStashesTranslations(): void
    {
        $input = ProductInput::make()
            ->field(ProductField::title('Hat')->withTranslation('fr', 'Chapeau'))
            ->field(ProductField::description('<p>en</p>'))
            ->field(ProductField::seoTitle('SEO Title'));

        self::assertSame([
            'title'           => 'Hat',
            'descriptionHtml' => '<p>en</p>',
            'seo'             => ['title' => 'SEO Title'],
        ], $input->toArray());

        // only the title field carries a translation, so only it is stashed
        $stashed = $input->translatableFields();
        self::assertCount(1, $stashed);
        self::assertSame('title', $stashed[0]->key());
    }

    public function testCarriesInlineMetafields(): void
    {
        $input = ProductInput::make('X')
            ->metafield(Metafield::make('specs', 'cap', MetafieldType::NumberInteger, 25));

        self::assertSame([
            'title'      => 'X',
            'metafields' => [
                ['namespace' => 'specs', 'key' => 'cap', 'type' => 'number_integer', 'value' => '25'],
            ],
        ], $input->toArray());
    }
}
