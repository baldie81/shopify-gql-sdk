<?php

declare(strict_types=1);

/**
 * Dev tool: download the public Shopify Admin GraphQL schema (introspection)
 * for one or more API versions and store each introspection result as JSON
 * under tests/fixtures/schema/, where the schema-validation test picks it up.
 *
 *   php bin/fetch-schema.php                # every supported version
 *   php bin/fetch-schema.php 2026-10 …      # just the versions named
 */

use Baldie81\ShopifyGraphQL\Configuration;
use GraphQL\Type\Introspection;

require dirname(__DIR__) . '/vendor/autoload.php';

$versions = array_slice($argv, 1) ?: Configuration::SUPPORTED_API_VERSIONS;
foreach ($versions as $version) {
    if (preg_match('/^(\d{4}-\d{2}|unstable)$/', $version) !== 1) {
        fwrite(STDERR, "Invalid API version: {$version}\n");
        exit(1);
    }
}

$query  = Introspection::getIntrospectionQuery();
$failed = false;

foreach ($versions as $version) {
    $target  = dirname(__DIR__) . "/tests/fixtures/schema/{$version}.json";
    $context = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => json_encode(['query' => $query]),
        'timeout' => 120,
    ]]);

    $raw     = @file_get_contents("https://shopify.dev/admin-graphql-direct-proxy/{$version}", false, $context);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;

    if (!isset($decoded['data']['__schema'])) {
        // Shopify stops serving a version about a year after its release: when
        // that happens, drop it from Configuration::SUPPORTED_API_VERSIONS.
        fwrite(STDERR, "Could not fetch the {$version} schema.\n" . substr((string) $raw, 0, 500) . "\n");
        $failed = true;

        continue;
    }

    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0777, true);
    }

    file_put_contents($target, json_encode($decoded['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    echo "Wrote {$target} (" . filesize($target) . " bytes)\n";
}

exit($failed ? 1 : 0);
