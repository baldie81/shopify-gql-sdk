<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Support\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /**
     * @return iterable<string,array{string|int|float,string}>
     */
    public static function amounts(): iterable
    {
        yield 'string kept exactly'            => ['15.99', '15.99'];
        yield 'three-decimal currency'         => ['10.005', '10.005'];
        yield 'whole string padded'            => ['40', '40.00'];
        yield 'one decimal padded'             => ['19.9', '19.90'];
        yield 'surrounding whitespace trimmed' => [' 5.50 ', '5.50'];
        yield 'float'                          => [17.5, '17.50'];
        yield 'float with three decimals'      => [10.005, '10.005'];
        yield 'float noise is rounded away'    => [0.1 + 0.2, '0.30'];
        yield 'int'                            => [24, '24.00'];
    }

    #[DataProvider('amounts')]
    public function testDecimal(string|int|float $in, string $expected): void
    {
        self::assertSame($expected, Money::decimal($in));
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'comma decimal' => ['1,50'];
        yield 'currency sign' => ['€5'];
        yield 'empty'         => [''];
        yield 'words'         => ['free'];
        yield 'thousands sep' => ['1,000.00'];
    }

    #[DataProvider('invalid')]
    public function testRejectsAnythingThatIsNotAPlainDecimal(string $in): void
    {
        // Previously `'1,50'` was silently sent as 1.00.
        $this->expectException(\InvalidArgumentException::class);
        Money::decimal($in);
    }
}
