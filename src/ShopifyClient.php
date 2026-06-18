<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Baldie81\ShopifyGraphQL\GraphQL\Client as GraphQLClient;
use Baldie81\ShopifyGraphQL\Resource\Products;

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

        return new self(new GraphQLClient($guzzle));
    }

    /**
     * Build a client around a pre-configured Guzzle client (custom
     * middleware/handlers). Its `base_uri` must point at the versioned Admin API
     * root (`.../admin/api/<version>/`) and carry the access-token header.
     */
    public static function fromGuzzle(ClientInterface $client): self
    {
        return new self(new GraphQLClient($client));
    }

    public function graphql(): GraphQLClient
    {
        return $this->gql;
    }

    public function products(): Products
    {
        return $this->products ??= new Products($this->gql);
    }
}
