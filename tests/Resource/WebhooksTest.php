<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Tests\Resource;

use GuzzleHttp\Psr7\Response;
use Baldie81\ShopifyGraphQL\Enum\WebhookTopic;
use Baldie81\ShopifyGraphQL\Tests\MockClientTestCase;

final class WebhooksTest extends MockClientTestCase
{
    public function testCreateUsesCallbackUrlOnOlderVersion(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1', 'topic' => 'ORDERS_CREATE'], 'userErrors' => []]],
        ]))], '2025-07'); // pre-2025-10 version

        $result = $client->webhooks()->create(WebhookTopic::OrdersCreate, 'https://example.com/hooks/orders', ['includeFields' => ['id', 'name']]);

        self::assertSame('gid://shopify/WebhookSubscription/1', $result['webhookSubscription']['id']);
        $vars = $this->body()['variables'];
        self::assertSame('ORDERS_CREATE', $vars['topic']);
        self::assertSame('https://example.com/hooks/orders', $vars['sub']['callbackUrl']); // 2025-07 -> callbackUrl
        self::assertArrayNotHasKey('uri', $vars['sub']);
        self::assertSame(['id', 'name'], $vars['sub']['includeFields']);
    }

    public function testCreateUsesUriOn2025_10Plus(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'], 'userErrors' => []]],
        ]))], '2026-04');

        $client->webhooks()->create(WebhookTopic::OrdersCreate, 'https://example.com/hooks/orders');

        $sub = $this->body()['variables']['sub'];
        self::assertSame('https://example.com/hooks/orders', $sub['uri']); // 2025-10+ -> uri
        self::assertArrayNotHasKey('callbackUrl', $sub);
    }

    public function testCreateAcceptsRawTopicString(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/2'], 'userErrors' => []]],
        ]))]);

        $client->webhooks()->create('CARTS_UPDATE', 'https://example.com/hooks/carts');

        self::assertSame('CARTS_UPDATE', $this->body()['variables']['topic']);
    }

    public function testDeleteReturnsDeletedId(): void
    {
        $client = $this->mockClient([new Response(200, [], (string) json_encode([
            'data' => ['webhookSubscriptionDelete' => ['deletedWebhookSubscriptionId' => 'gid://shopify/WebhookSubscription/1', 'userErrors' => []]],
        ]))]);

        $result = $client->webhooks()->delete('1');

        self::assertSame('gid://shopify/WebhookSubscription/1', $result['deletedWebhookSubscriptionId']);
        self::assertSame('gid://shopify/WebhookSubscription/1', $this->body()['variables']['id']);
    }
}
