<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Enum\ProductStatus;
use Baldie81\ShopifyGraphQL\Support\Arrayable;
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
}
