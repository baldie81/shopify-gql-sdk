<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Baldie81\ShopifyGraphQL\GraphQL\Client as GraphQLClient;
use Baldie81\ShopifyGraphQL\Resource\BulkOperations;
use Baldie81\ShopifyGraphQL\Resource\Collections;
use Baldie81\ShopifyGraphQL\Resource\Customers;
use Baldie81\ShopifyGraphQL\Resource\DraftOrders;
use Baldie81\ShopifyGraphQL\Resource\Files;
use Baldie81\ShopifyGraphQL\Resource\FulfillmentOrders;
use Baldie81\ShopifyGraphQL\Resource\GiftCards;
use Baldie81\ShopifyGraphQL\Resource\Inventory;
use Baldie81\ShopifyGraphQL\Resource\MetafieldDefinitions;
use Baldie81\ShopifyGraphQL\Resource\Orders;
use Baldie81\ShopifyGraphQL\Resource\PriceLists;
use Baldie81\ShopifyGraphQL\Resource\Products;
use Baldie81\ShopifyGraphQL\Resource\Translations;
use Baldie81\ShopifyGraphQL\Resource\Variants;
use Baldie81\ShopifyGraphQL\Resource\Webhooks;

/**
 * Entry point for the SDK. Exposes the Shopify Admin GraphQL resources as
 * lazily-created sub-clients over a shared transport.
 *
 *   $shopify = ShopifyClient::create(new Configuration(
 *       shop:        'my-store',
 *       accessToken: 'shpat_...',
 *   ));
 *   $shopify->products()->get('123');
 */
final class ShopifyClient
{
    public const string DEFAULT_API_VERSION = '2026-10';

    private ?Products $products = null;
    private ?BulkOperations $bulkOperations = null;
    private ?Translations $translations = null;
    private ?Files $files = null;
    private ?MetafieldDefinitions $metafieldDefinitions = null;
    private ?Variants $variants = null;
    private ?Inventory $inventory = null;
    private ?Customers $customers = null;
    private ?Orders $orders = null;
    private ?Collections $collections = null;
    private ?FulfillmentOrders $fulfillmentOrders = null;
    private ?PriceLists $priceLists = null;
    private ?DraftOrders $draftOrders = null;
    private ?GiftCards $giftCards = null;
    private ?Webhooks $webhooks = null;

    /**
     * @param ClientInterface|null $uploader   plain client (no Shopify auth) for pushing
     *                                         bytes to staged-upload targets
     * @param ClientInterface|null $downloader plain client for downloading bulk results
     */
    public function __construct(
        private readonly GraphQLClient $gql,
        private readonly ?ClientInterface $uploader = null,
        private readonly ?ClientInterface $downloader = null,
    ) {}

    /**
     * Build a client from connection settings (the common case). The TLS and
     * connect-timeout settings also apply to the side channels (staged uploads,
     * bulk-result downloads), which talk to storage hosts rather than Shopify.
     */
    public static function create(Configuration $config): self
    {
        $guzzle = new GuzzleClient([
            'base_uri'        => $config->baseUri(),
            'timeout'         => $config->timeout,
            'connect_timeout' => $config->connectTimeout,
            'verify'          => $config->verifySsl,
            'headers'         => [
                'X-Shopify-Access-Token' => $config->accessToken,
                'Content-Type'           => 'application/json',
                'Accept'                 => 'application/json',
            ],
        ]);

        $sideChannel = ['connect_timeout' => $config->connectTimeout, 'verify' => $config->verifySsl];

        return new self(
            new GraphQLClient($guzzle, maxRetries: $config->maxRetries, apiVersion: $config->apiVersion),
            new GuzzleClient($sideChannel + ['timeout' => max($config->timeout, Files::UPLOAD_TIMEOUT)]),
            new GuzzleClient($sideChannel + ['timeout' => 0, 'read_timeout' => max($config->timeout, 60.0)]),
        );
    }

    /**
     * Build a client around a pre-configured Guzzle client (custom
     * middleware/handlers). Its `base_uri` must point at the versioned Admin API
     * root (`.../admin/api/<version>/`) and carry the access-token header.
     *
     * The API version (which gates version-specific behaviour) is read from that
     * `base_uri`; pass `$apiVersion` only when it can't be — a value that
     * contradicts the `base_uri` is rejected.
     *
     * @throws \InvalidArgumentException when `$apiVersion` and the `base_uri` disagree
     */
    public static function fromGuzzle(
        ClientInterface $client,
        ?string $apiVersion = null,
        int $maxRetries = 2,
        ?ClientInterface $uploader = null,
        ?ClientInterface $downloader = null,
    ): self {
        $fromUri = self::versionFromBaseUri($client);

        if ($apiVersion !== null && $fromUri !== null && $apiVersion !== $fromUri) {
            throw new \InvalidArgumentException(
                "API version mismatch: \"{$apiVersion}\" was passed but the client's base_uri targets \"{$fromUri}\".",
            );
        }

        $version = $apiVersion ?? $fromUri ?? self::DEFAULT_API_VERSION;

        return new self(new GraphQLClient($client, maxRetries: $maxRetries, apiVersion: $version), $uploader, $downloader);
    }

    private static function versionFromBaseUri(ClientInterface $client): ?string
    {
        if (!$client instanceof GuzzleClient) {
            return null;
        }

        $baseUri = (string) ($client->getConfig('base_uri') ?? '');

        return preg_match('#/admin/api/([^/]+)/?#', $baseUri, $m) === 1 ? $m[1] : null;
    }

    public function graphql(): GraphQLClient
    {
        return $this->gql;
    }

    public function products(): Products
    {
        return $this->products ??= new Products($this->gql, $this->files(), $this->translations());
    }

    public function bulkOperations(): BulkOperations
    {
        return $this->bulkOperations ??= new BulkOperations($this->gql, null, $this->files(), $this->downloader);
    }

    public function translations(): Translations
    {
        return $this->translations ??= new Translations($this->gql);
    }

    public function files(): Files
    {
        return $this->files ??= new Files($this->gql, $this->uploader);
    }

    public function metafieldDefinitions(): MetafieldDefinitions
    {
        return $this->metafieldDefinitions ??= new MetafieldDefinitions($this->gql);
    }

    public function variants(): Variants
    {
        return $this->variants ??= new Variants($this->gql);
    }

    public function inventory(): Inventory
    {
        return $this->inventory ??= new Inventory($this->gql);
    }

    public function customers(): Customers
    {
        return $this->customers ??= new Customers($this->gql);
    }

    public function orders(): Orders
    {
        return $this->orders ??= new Orders($this->gql);
    }

    public function collections(): Collections
    {
        return $this->collections ??= new Collections($this->gql);
    }

    public function fulfillmentOrders(): FulfillmentOrders
    {
        return $this->fulfillmentOrders ??= new FulfillmentOrders($this->gql);
    }

    public function priceLists(): PriceLists
    {
        return $this->priceLists ??= new PriceLists($this->gql);
    }

    public function draftOrders(): DraftOrders
    {
        return $this->draftOrders ??= new DraftOrders($this->gql);
    }

    public function giftCards(): GiftCards
    {
        return $this->giftCards ??= new GiftCards($this->gql);
    }

    public function webhooks(): Webhooks
    {
        return $this->webhooks ??= new Webhooks($this->gql);
    }
}
