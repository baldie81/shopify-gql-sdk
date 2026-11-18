<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Support\Arrayable;
use Baldie81\ShopifyGraphQL\Support\VariantInput;
use PHPUnit\Framework\TestCase;

final class VariantInputTest extends TestCase
{
    public function testBuildsVariantAndInventoryItemSubtree(): void
    {
        $input = VariantInput::make('gid://shopify/ProductVariant/1')
            ->price(19.9)
            ->compareAtPrice(24)
            ->sku('TEE-RED-S')
            ->tracked();

        self::assertInstanceOf(Arrayable::class, $input);
        self::assertSame([
            'id'             => 'gid://shopify/ProductVariant/1',
            'price'          => '19.90',          // floats formatted to money strings
            'compareAtPrice' => '24.00',
            'inventoryItem'  => ['sku' => 'TEE-RED-S', 'tracked' => true],
        ], $input->toArray());
    }

    public function testStringPricePassesThrough(): void
    {
        self::assertSame('15.99', VariantInput::make('1')->price('15.99')->toArray()['price']);
    }

    public function testNumericIdIsCoercedToAVariantGid(): void
    {
        self::assertSame('gid://shopify/ProductVariant/42', VariantInput::make('42')->toArray()['id']);
    }

    public function testNewVariantHasNoIdAndCarriesOptionValues(): void
    {
        $input = VariantInput::new()->optionValue('Size', 'XL')->optionValue('Color', 'Red')->price('9.99')->toArray();

        self::assertArrayNotHasKey('id', $input);
        self::assertSame(
            [['optionName' => 'Size', 'name' => 'XL'], ['optionName' => 'Color', 'name' => 'Red']],
            $input['optionValues'],
        );
    }

    public function testBarcodeIsAVariantFieldNotAnInventoryItemField(): void
    {
        $input = VariantInput::make('1')->barcode('1234567890123')->sku('S')->toArray();

        self::assertSame('1234567890123', $input['barcode']);
        self::assertSame(['sku' => 'S'], $input['inventoryItem']); // InventoryItemInput has no barcode
    }

    public function testInvalidPriceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        VariantInput::make('1')->price('9,99');
    }
}
