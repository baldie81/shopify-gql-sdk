<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\Enum\WebhookTopic;
use Baldie81\ShopifyGraphQL\ShopifyClient;

/**
 * WEBHOOKS — subscribe an endpoint to a topic so Shopify pushes you events
 * (orders, products, …) instead of you polling. Reached via `$shopify->webhooks()`.
 *
 *   $ex = new WebhookExamples(ExampleClient::create());
 *
 * Real-life usage: webhook subscriptions are part of your app's setup — register
 * each topic → endpoint once (idempotently). The topic is a {@see WebhookTopic}
 * (or a raw string for topics not in the enum); the endpoint is an HTTPS URL.
 *
 *   foreach ($config['webhooks'] as [$topic, $url]) {
 *       $ex->subscribe($topic, $url);
 *   }
 *
 * Operations shown in this file:
 *   - subscribe($topic, $uri, $options) — webhookSubscriptionCreate
 *   - list()                            — all subscriptions
 *   - get($id)                          — read one
 *   - update($id, $uri)                 — point a subscription at a new endpoint
 *   - delete($id)                       — unsubscribe
 *
 * Notes:
 *   - The endpoint is passed as `uri`; the SDK maps it to `callbackUrl`
 *     automatically on ≤2025-07 (where `uri` doesn't exist yet) — your call is the
 *     same across versions.
 *   - `includeFields` / `metafieldNamespaces` / `filter` go in `$options`.
 *   - Verify the `X-Shopify-Hmac-Sha256` signature on the receiving end.
 *
 * Related: every resource (webhooks notify you of their changes).
 */
final class WebhookExamples
{
    public function __construct(private readonly ShopifyClient $shopify) {}

    /**
     * Subscribe an endpoint to a topic. `$options` may add `includeFields`,
     * `metafieldNamespaces`, or a `filter`.
     *
     * @param array<string,mixed> $options extra WebhookSubscriptionInput fields
     */
    public function subscribe(
        WebhookTopic|string $topic = WebhookTopic::OrdersCreate,
        string $uri = 'https://example.com/webhooks/orders',
        array $options = ['includeFields' => ['id', 'name']],
    ): array {
        return $this->shopify->webhooks()->create($topic, $uri, $options);
    }

    /**
     * All webhook subscriptions on the shop.
     */
    public function list(): array
    {
        return $this->shopify->webhooks()->all();
    }

    /**
     * Read one subscription.
     */
    public function get(string $id): ?array
    {
        return $this->shopify->webhooks()->get($id);
    }

    /**
     * Point a subscription at a new endpoint (pass `uri`; mapped per version).
     */
    public function update(string $id, string $uri = 'https://example.com/webhooks/orders-v2'): array
    {
        return $this->shopify->webhooks()->update($id, ['uri' => $uri]);
    }

    /**
     * Unsubscribe (delete) a webhook subscription.
     */
    public function delete(string $id): array
    {
        return $this->shopify->webhooks()->delete($id);
    }
}
