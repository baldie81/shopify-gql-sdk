<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Support\PriceListInput;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class PriceListsTest extends MockClientTestCase
{
    public function testCreateBuildsParentAdjustment(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['priceListCreate' => ['priceList' => ['id' => 'gid://shopify/PriceList/1', 'name' => 'Wholesale'], 'userErrors' => []]],
        ]))]);

        $result = $client->priceLists()->create(
            PriceListInput::make('Wholesale')->currency('EUR')->catalogId('gid://shopify/Catalog/5')->adjustment('PERCENTAGE_DECREASE', 20)
        );

        self::assertSame('gid://shopify/PriceList/1', $result['priceList']['id']);
        $input = $this->body()['variables']['input'];
        self::assertSame('EUR', $input['currency']);
        self::assertSame('PERCENTAGE_DECREASE', $input['parent']['adjustment']['type']);
        self::assertEquals(20, $input['parent']['adjustment']['value']); // 20.0 -> 20 over JSON
    }

    public function testSetPricesCoercesVariantGidAndChunks(): void
    {
        $ok = (string) json_encode(['data' => ['priceListFixedPricesAdd' => ['prices' => [], 'userErrors' => []]]]);
        $client = $this->mockClient([new Response(200, [], $ok), new Response(200, [], $ok)]);

        $prices = [];
        for ($i = 0; $i < 300; $i++) {
            $prices[] = ['variantId' => (string) $i, 'price' => ['amount' => '9.99', 'currencyCode' => 'EUR']];
        }
        $client->priceLists()->setPrices('1', $prices);

        self::assertCount(2, $this->history); // 300 -> 250 + 50
        self::assertSame('gid://shopify/ProductVariant/0', $this->body(0)['variables']['prices'][0]['variantId']);
        self::assertCount(250, $this->body(0)['variables']['prices']);
        self::assertSame('gid://shopify/PriceList/1', $this->body(0)['variables']['priceListId']);
    }

    public function testDeletePricesCoercesVariantIds(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['priceListFixedPricesDelete' => ['deletedFixedPriceVariantIds' => ['gid://shopify/ProductVariant/5'], 'userErrors' => []]],
        ]))]);

        $result = $client->priceLists()->deletePrices('1', ['5']);

        self::assertSame(['gid://shopify/ProductVariant/5'], $result['deletedFixedPriceVariantIds']);
        self::assertSame(['gid://shopify/ProductVariant/5'], $this->body()['variables']['variantIds']);
    }

    public function testDeleteReturnsDeletedId(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['priceListDelete' => ['deletedId' => 'gid://shopify/PriceList/1', 'userErrors' => []]],
        ]))]);

        $result = $client->priceLists()->delete('1');

        self::assertSame('gid://shopify/PriceList/1', $result['deletedId']);
    }

    public function testUpdatePricesSendsAtMost250OfEachPerCallAndMergesTheResults(): void
    {
        $reply = static fn (array $added, array $deleted): Response => new Response(200, [], (string) json_encode(['data' => ['priceListFixedPricesUpdate' => [
            'priceList' => ['id' => 'gid://shopify/PriceList/3'], 'pricesAdded' => $added, 'deletedFixedPriceVariantIds' => $deleted, 'userErrors' => [],
        ]]]));
        $client = $this->mockClient([
            $reply([['variant' => ['id' => 'gid://shopify/ProductVariant/1']]], ['gid://shopify/ProductVariant/900']),
            $reply([['variant' => ['id' => 'gid://shopify/ProductVariant/251']]], []),
        ]);

        $prices = array_map(static fn (int $i): array => ['variantId' => (string) $i, 'price' => ['amount' => '1.00', 'currencyCode' => 'EUR']], range(1, 251));
        $result = $client->priceLists()->updatePrices('3', $prices, ['900']);

        self::assertCount(2, $this->history);
        self::assertCount(250, $this->body(0)['variables']['pricesToAdd']);
        self::assertSame(['gid://shopify/ProductVariant/900'], $this->body(0)['variables']['variantIdsToDelete']);
        self::assertCount(1, $this->body(1)['variables']['pricesToAdd']);
        self::assertSame([], $this->body(1)['variables']['variantIdsToDelete']);

        self::assertCount(2, $result['pricesAdded']);
        self::assertSame(['gid://shopify/ProductVariant/900'], $result['deletedFixedPriceVariantIds']);
        self::assertSame('gid://shopify/PriceList/3', $result['priceList']['id']);
    }

    public function testDeletePricesChunks(): void
    {
        $reply  = static fn (): Response => new Response(200, [], (string) json_encode(['data' => ['priceListFixedPricesDelete' => ['deletedFixedPriceVariantIds' => ['x'], 'userErrors' => []]]]));
        $client = $this->mockClient([$reply(), $reply()]);

        $result = $client->priceLists()->deletePrices('3', range(1, 251));

        self::assertCount(250, $this->body(0)['variables']['variantIds']);
        self::assertCount(1, $this->body(1)['variables']['variantIds']);
        self::assertCount(2, $result['deletedFixedPriceVariantIds']);
    }
}
