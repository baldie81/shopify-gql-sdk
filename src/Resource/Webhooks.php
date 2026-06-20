<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use Baldie81\ShopifyGraphQL\Enum\WebhookTopic;
use Baldie81\ShopifyGraphQL\Support\Gid;

/**
 * Webhook subscriptions — subscribe an endpoint to a topic so Shopify pushes you
 * events (orders, products, …) instead of you polling.
 *
 * The endpoint is given as a `uri`: an HTTPS URL for HTTP webhooks, or a
 * `pubsub://project:topic` / EventBridge ARN for those transports. Shopify added
 * `uri` in 2025-10 (replacing `callbackUrl`); the SDK keeps your call the same
 * and maps to `callbackUrl` automatically on ≤2025-07.
 *
 * @link https://shopify.dev/docs/api/admin-graphql/latest/objects/WebhookSubscription Shopify Admin GraphQL: WebhookSubscription
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/webhookSubscriptionCreate Shopify Admin GraphQL: webhookSubscriptionCreate
 * @link https://shopify.dev/docs/api/admin-graphql/latest/enums/WebhookSubscriptionTopic Shopify Admin GraphQL: WebhookSubscriptionTopic
 */
final class Webhooks extends AbstractResource
{
    private const FIELDS = 'id topic format includeFields filter createdAt updatedAt'
        . ' endpoint { __typename ... on WebhookHttpEndpoint { callbackUrl } ... on WebhookEventBridgeEndpoint { arn } ... on WebhookPubSubEndpoint { pubSubProject pubSubTopic } }';

    /**
     * @return array{nodes:list<array<string,mixed>>,pageInfo:array<string,mixed>}
     */
    public function list(int $first = 50, ?string $after = null): array
    {
        $first = max(1, min($first, self::MAX_PAGE_SIZE));
        $data  = $this->gql->execute(self::listQuery(), ['first' => $first, 'after' => $after]);

        return $data['webhookSubscriptions'] ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(int $pageSize = self::MAX_PAGE_SIZE): array
    {
        return $this->paginateNodes(self::listQuery(), [], 'webhookSubscriptions', $pageSize);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $id): ?array
    {
        $query = 'query ($id: ID!) { webhookSubscription(id: $id) { ' . self::FIELDS . ' } }';

        return $this->gql->execute($query, ['id' => Gid::webhookSubscription($id)])['webhookSubscription'] ?? null;
    }

    /**
     * Subscribe `$uri` to `$topic`. `$uri` is an HTTPS URL (or pubsub://…/ARN).
     * `$options` may add `includeFields`, `metafieldNamespaces`, `filter`.
     *
     * @param array<string,mixed> $options extra WebhookSubscriptionInput fields
     * @return array{webhookSubscription:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function create(WebhookTopic|string $topic, string $uri, array $options = []): array
    {
        $mutation = 'mutation ($topic: WebhookSubscriptionTopic!, $sub: WebhookSubscriptionInput!) {'
            . ' webhookSubscriptionCreate(topic: $topic, webhookSubscription: $sub) {'
            . ' webhookSubscription { ' . self::FIELDS . ' } userErrors { field message } } }';

        $sub = $options;
        $sub[$this->endpointField()] = $uri;

        return $this->gql->execute($mutation, [
            'topic' => $topic instanceof WebhookTopic ? $topic->value : $topic,
            'sub'   => $sub,
        ])['webhookSubscriptionCreate'] ?? ['webhookSubscription' => null, 'userErrors' => []];
    }

    /**
     * Update a subscription (e.g. change its `uri` or `includeFields`).
     *
     * @param array<string,mixed> $input a WebhookSubscriptionInput
     * @return array{webhookSubscription:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function update(string $id, array $input): array
    {
        // Let callers always use `uri`; downgrade to callbackUrl on ≤2025-07.
        if ($this->endpointField() === 'callbackUrl' && array_key_exists('uri', $input)) {
            $input['callbackUrl'] = $input['uri'];
            unset($input['uri']);
        }

        $mutation = 'mutation ($id: ID!, $sub: WebhookSubscriptionInput!) {'
            . ' webhookSubscriptionUpdate(id: $id, webhookSubscription: $sub) {'
            . ' webhookSubscription { ' . self::FIELDS . ' } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['id' => Gid::webhookSubscription($id), 'sub' => $input])['webhookSubscriptionUpdate'] ?? ['webhookSubscription' => null, 'userErrors' => []];
    }

    /**
     * @return array{deletedWebhookSubscriptionId:?string,userErrors:list<array<string,mixed>>}
     */
    public function delete(string $id): array
    {
        $mutation = 'mutation ($id: ID!) { webhookSubscriptionDelete(id: $id) { deletedWebhookSubscriptionId userErrors { field message } } }';

        return $this->gql->execute($mutation, ['id' => Gid::webhookSubscription($id)])['webhookSubscriptionDelete'] ?? ['deletedWebhookSubscriptionId' => null, 'userErrors' => []];
    }

    /**
     * The endpoint input field for the configured API version: `uri` on 2025-10+,
     * `callbackUrl` on older versions.
     */
    private function endpointField(): string
    {
        return $this->gql->apiVersionAtLeast('2025-10') ? 'uri' : 'callbackUrl';
    }

    private static function listQuery(): string
    {
        return 'query ($first: Int!, $after: String) {'
            . ' webhookSubscriptions(first: $first, after: $after) {'
            . ' nodes { ' . self::FIELDS . ' } pageInfo { hasNextPage endCursor } } }';
    }
}
