<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Baldie81\ShopifyGraphQL\GraphQL\Client as GraphQLClient;
use Baldie81\ShopifyGraphQL\Resource\BulkOperations;
use Baldie81\ShopifyGraphQL\Resource\Customers;
use Baldie81\ShopifyGraphQL\Resource\Files;
use Baldie81\ShopifyGraphQL\Resource\Inventory;
use Baldie81\ShopifyGraphQL\Resource\MetafieldDefinitions;
use Baldie81\ShopifyGraphQL\Resource\Orders;
use Baldie81\ShopifyGraphQL\Resource\Products;
use Baldie81\ShopifyGraphQL\Resource\Translations;
use Baldie81\ShopifyGraphQL\Resource\Variants;

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
    private ?Products $products = null;
    private ?BulkOperations $bulkOperations = null;
    private ?Translations $translations = null;
    private ?Files $files = null;
    private ?MetafieldDefinitions $metafieldDefinitions = null;
    private ?Variants $variants = null;
    private ?Inventory $inventory = null;
    private ?Customers $customers = null;
    private ?Orders $orders = null;

    public function __construct(
        private readonly GraphQLClient $gql,
    ) {}

    /**
     * Build a client from connection settings (the common case).
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

        return new self(new GraphQLClient($guzzle, apiVersion: $config->apiVersion));
    }

    /**
     * Build a client around a pre-configured Guzzle client (custom
     * middleware/handlers). Its `base_uri` must point at the versioned Admin API
     * root (`.../admin/api/<version>/`) and carry the access-token header.
     */
    public static function fromGuzzle(ClientInterface $client, string $apiVersion = '2025-07'): self
    {
        return new self(new GraphQLClient($client, apiVersion: $apiVersion));
    }

    public function graphql(): GraphQLClient
    {
        return $this->gql;
    }

    public function products(): Products
    {
        return $this->products ??= new Products($this->gql);
    }

    public function bulkOperations(): BulkOperations
    {
        return $this->bulkOperations ??= new BulkOperations($this->gql);
    }

    public function translations(): Translations
    {
        return $this->translations ??= new Translations($this->gql);
    }

    public function files(): Files
    {
        return $this->files ??= new Files($this->gql);
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
}
