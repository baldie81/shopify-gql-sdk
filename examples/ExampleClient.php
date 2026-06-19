<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Examples;

use Baldie81\ShopifyGraphQL\Configuration;
use Baldie81\ShopifyGraphQL\ShopifyClient;

/**
 * Builds a {@see ShopifyClient} for the examples.
 *
 * The credentials below are dummy placeholders — replace them with your store's
 * domain and Admin API access token (or wire this to your own config/secret
 * store). Don't commit real tokens.
 */
final class ExampleClient
{
    public static function create(): ShopifyClient
    {
        return ShopifyClient::create(new Configuration(
            shop:        'your-store.myshopify.com',     // your *.myshopify.com domain
            accessToken: 'shpat_xxxxxxxxxxxxxxxxxxxxxxxx', // Admin API access token
            apiVersion:  '2025-07',
        ));
    }
}
