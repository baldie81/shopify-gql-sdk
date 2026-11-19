<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Enum\MetafieldType;
use Baldie81\ShopifyGraphQL\Support\Metafield;
use PHPUnit\Framework\TestCase;

final class MetafieldTest extends TestCase
{
    public function testMakeSerialisesValueByType(): void
    {
        self::assertSame(
            ['namespace' => 'specs', 'key' => 'cap', 'type' => 'number_integer', 'value' => '25'],
            Metafield::make('specs', 'cap', MetafieldType::NumberInteger, 25)->toArray(),
        );
    }

    public function testBooleanAndJsonSerialisation(): void
    {
        self::assertSame('true', Metafield::boolean('f', 'b', true)->toArray()['value']);
        self::assertSame('false', Metafield::boolean('f', 'b', false)->toArray()['value']);

        $json = Metafield::json('f', 'matrix', ['rows' => 3, 'cols' => 4])->toArray();
        self::assertSame('json', $json['type']);
        self::assertSame('{"rows":3,"cols":4}', $json['value']);
    }

    public function testStructuredTypeTakesJsonObject(): void
    {
        $volume = Metafield::make('f', 'v', MetafieldType::Volume, ['value' => 1.5, 'unit' => 'LITERS'])->toArray();
        self::assertSame('volume', $volume['type']);
        self::assertSame('{"value":1.5,"unit":"LITERS"}', $volume['value']);
    }

    public function testRawTypeStringIsAccepted(): void
    {
        self::assertSame('custom_thing', Metafield::make('f', 'k', 'custom_thing', 'v')->toArray()['type']);
    }

    public function testStringValuePassesThroughUnchanged(): void
    {
        // A pre-encoded JSON string must not be double-encoded.
        self::assertSame('[1,2,3]', Metafield::make('f', 'k', MetafieldType::Json, '[1,2,3]')->toArray()['value']);
    }

    public function testReferenceUsesGidValue(): void
    {
        $ref = Metafield::reference('f', 'related', 'gid://shopify/Product/9')->toArray();
        self::assertSame('product_reference', $ref['type']);
        self::assertSame('gid://shopify/Product/9', $ref['value']);
    }


    public function testFloatsAreNeverWrittenInExponentNotation(): void
    {
        $value = static fn (float $v): string => Metafield::make('n', 'k', 'number_decimal', $v)->toArray()['value'];

        self::assertSame('0.00001', $value(0.00001)); // PHP's own cast gives "1.0E-5"
        self::assertSame('1.5', $value(1.5));
        self::assertSame('3', $value(3.0));
        self::assertSame('-0.0000025', $value(-0.0000025));
    }
}
