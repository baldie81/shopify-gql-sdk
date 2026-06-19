<?php

/**
 * Live smoke test for the Products resource (Shopify Admin GraphQL).
 *
 * READ-ONLY by default: searches products, fetches one, paginates. With
 * SHOPIFY_SMOKE_WRITE=1 it also creates a DRAFT product (unpublished) and
 * updates it. The product is left in place — this spike has no productDelete.
 *
 *   SHOPIFY_SHOP=my-store SHOPIFY_TOKEN=shpat_xxx php examples_local/products_smoke_test.php
 *   SHOPIFY_SMOKE_WRITE=1 SHOPIFY_SHOP=... SHOPIFY_TOKEN=... php examples_local/products_smoke_test.php
 *
 * Required env: SHOPIFY_SHOP (e.g. "my-store" or "my-store.myshopify.com"),
 * SHOPIFY_TOKEN (Admin API access token). Optional: SHOPIFY_API_VERSION,
 * SHOPIFY_SMOKE_INSECURE. Never commit a real token.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Baldie81\ShopifyGraphQL\Configuration;
use Baldie81\ShopifyGraphQL\Enum\ProductStatus;
use Baldie81\ShopifyGraphQL\ShopifyClient;
use Baldie81\ShopifyGraphQL\Support\Gid;
use Baldie81\ShopifyGraphQL\Support\ProductInput;

$shop  = getenv('SHOPIFY_SHOP') ?: '';
$token = getenv('SHOPIFY_TOKEN') ?: '';
if ($shop === '' || $token === '') {
    fwrite(STDERR, "Set SHOPIFY_SHOP and SHOPIFY_TOKEN (and optionally SHOPIFY_API_VERSION).\n");
    exit(2);
}

$write    = (bool) getenv('SHOPIFY_SMOKE_WRITE');
$insecure = (bool) getenv('SHOPIFY_SMOKE_INSECURE');

$shopify = ShopifyClient::create(new Configuration(
    shop: $shop,
    accessToken: $token,
    apiVersion: getenv('SHOPIFY_API_VERSION') ?: '2025-07',
    verifySsl: !$insecure,
));

$pass = 0;
$fail = 0;

/** Run one check; print ✓/✗ and a short summary; never abort the run. */
$check = function (string $label, callable $fn) use (&$pass, &$fail): mixed {
    try {
        $result  = $fn();
        $summary = is_string($result) ? $result : '';
        printf("  \033[32m✓\033[0m %-40s %s\n", $label, $summary);
        $pass++;
        return $result;
    } catch (\Throwable $e) {
        printf("  \033[31m✗\033[0m %-40s %s: %s\n", $label, get_class($e), $e->getMessage());
        $fail++;
        return null;
    }
};

$section = static fn (string $title) => print("\n\033[1m{$title}\033[0m\n");

printf("Shopify Products smoke test → %s  (writes: %s)\n", $shop, $write ? 'ON' : 'off');

// ---------------------------------------------------------------------------
$section('Search & read');

$firstId = null;
$check('products: search (1 page)', function () use ($shopify, &$firstId): string {
    $page    = $shopify->products()->search('', first: 5);
    $firstId = $page['nodes'][0]['id'] ?? null;
    return count($page['nodes']) . ' node(s), hasNextPage=' . var_export($page['pageInfo']['hasNextPage'] ?? null, true);
});

if ($firstId !== null) {
    $check('products: get(id)', function () use ($shopify, $firstId): string {
        $p = $shopify->products()->get($firstId);
        return "id={$p['id']}, title=" . ($p['title'] ?? '?') . ', status=' . ($p['status'] ?? '?');
    });
}

$check('products: searchAll (status:active)', function () use ($shopify): string {
    return count($shopify->products()->searchAll('status:active', pageSize: 50)) . ' active product(s)';
});

// ---------------------------------------------------------------------------
$section('Missing entity');

$check('get(bogus id) → null', function () use ($shopify): string {
    $p = $shopify->products()->get(Gid::product(999999999999));
    return $p === null ? 'null (as expected)' : 'unexpectedly found one';
});

// ---------------------------------------------------------------------------
if ($write) {
    $section('WRITES (creating a DRAFT product)');

    $created = $check('products: create() (draft)', function () use ($shopify): array {
        return $shopify->products()->create(
            ProductInput::make('SDK Spike ' . date('H:i:s'))
                ->status(ProductStatus::Draft)
                ->vendor('SDK Spike')
                ->tags(['sdk-spike']),
        );
    });

    $newId = is_array($created) ? ($created['product']['id'] ?? null) : null;
    $errs  = is_array($created) ? ($created['userErrors'] ?? []) : [];

    if ($errs !== []) {
        printf("  userErrors: %s\n", json_encode($errs));
    }
    if ($newId !== null) {
        printf("  created %s\n", $newId);

        $check('products: update() (retitle + active)', function () use ($shopify, $newId): string {
            $res = $shopify->products()->update($newId, ProductInput::make('SDK Spike (updated)')->status(ProductStatus::Active));
            return $res['userErrors'] === []
                ? 'status=' . ($res['product']['status'] ?? '?')
                : 'userErrors: ' . json_encode($res['userErrors']);
        });

        printf("  note: product %s left in place (no productDelete in this spike).\n", $newId);
    }
}

// ---------------------------------------------------------------------------
printf("\n\033[1mResult:\033[0m %d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
