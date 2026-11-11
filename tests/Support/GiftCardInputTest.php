<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Support;

use Baldie81\ShopifyGraphQL\Support\GiftCardInput;
use PHPUnit\Framework\TestCase;

final class GiftCardInputTest extends TestCase
{
    public function testMakeDefaultsToEurInitialAmount(): void
    {
        self::assertSame(
            ['initialAmount' => ['amount' => '25.00', 'currencyCode' => 'EUR']],
            GiftCardInput::make(25)->toArray(),
        );
    }

    public function testMakeAcceptsExplicitCurrency(): void
    {
        self::assertSame(
            ['initialAmount' => ['amount' => '19.90', 'currencyCode' => 'USD']],
            GiftCardInput::make('19.9', 'usd')->toArray(),
        );
    }

    public function testInitialAmountReplacesAmount(): void
    {
        $input = GiftCardInput::make('10')->initialAmount('40', 'GBP');

        self::assertSame(['amount' => '40.00', 'currencyCode' => 'GBP'], $input->toArray()['initialAmount']);
    }

    public function testRecipientWithAllAttributes(): void
    {
        $input = GiftCardInput::make('50')
            ->customer('1')
            ->recipient('2', message: 'Happy birthday!', preferredName: 'Dad', sendAt: '2026-12-01T12:00:00Z');

        self::assertSame([
            'initialAmount'       => ['amount' => '50.00', 'currencyCode' => 'EUR'],
            'customerId'          => 'gid://shopify/Customer/1',
            'recipientAttributes' => [
                'id'                 => 'gid://shopify/Customer/2',
                'message'            => 'Happy birthday!',
                'preferredName'      => 'Dad',
                'sendNotificationAt' => '2026-12-01T12:00:00Z',
            ],
        ], $input->toArray());
    }

    public function testRecipientOmitsUnsetAttributesAndKeepsGid(): void
    {
        $input = GiftCardInput::make('50')->recipient('gid://shopify/Customer/2');

        self::assertSame(['id' => 'gid://shopify/Customer/2'], $input->toArray()['recipientAttributes']);
    }
}
