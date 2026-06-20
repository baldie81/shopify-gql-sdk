<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Support\GiftCardInput;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class GiftCardsTest extends MockClientTestCase
{
    public function testCreateReturnsOneTimeCode(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['giftCardCreate' => [
                'giftCard'     => ['id' => 'gid://shopify/GiftCard/1', 'lastCharacters' => 'WXYZ'],
                'giftCardCode' => 'ABCD1234WXYZ',
                'userErrors'   => [],
            ]],
        ]))]);

        $result = $client->giftCards()->create(GiftCardInput::make('50')->note('Loyalty'));

        self::assertSame('ABCD1234WXYZ', $result['giftCardCode']); // plaintext, only here
        self::assertSame('50.00', $this->body()['variables']['input']['initialValue']);
        self::assertSame('Loyalty', $this->body()['variables']['input']['note']);
    }

    public function testCreditSendsMoneyInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['giftCardCredit' => ['giftCardCreditTransaction' => ['id' => 'gid://shopify/GiftCardCreditTransaction/1'], 'userErrors' => []]],
        ]))]);

        $client->giftCards()->credit('1', 10, 'EUR', 'Goodwill');

        $input = $this->body()['variables']['creditInput'];
        self::assertSame(['amount' => '10.00', 'currencyCode' => 'EUR'], $input['creditAmount']);
        self::assertSame('Goodwill', $input['note']);
    }

    public function testDebitSendsMoneyInput(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['giftCardDebit' => ['giftCardDebitTransaction' => ['id' => 'gid://shopify/GiftCardDebitTransaction/1'], 'userErrors' => []]],
        ]))]);

        $client->giftCards()->debit('1', 5, 'EUR');

        self::assertSame(['amount' => '5.00', 'currencyCode' => 'EUR'], $this->body()['variables']['debitInput']['debitAmount']);
    }

    public function testDeactivateUsesDeactivateMutation(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['giftCardDeactivate' => ['giftCard' => ['id' => 'gid://shopify/GiftCard/1', 'enabled' => false], 'userErrors' => []]],
        ]))]);

        $client->giftCards()->deactivate('1');

        self::assertStringContainsString('giftCardDeactivate(id: $id)', $this->body()['query']);
        self::assertSame('gid://shopify/GiftCard/1', $this->body()['variables']['id']);
    }
}
