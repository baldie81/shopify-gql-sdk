<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Support;

/**
 * Helpers for Shopify global IDs (`gid://shopify/<Type>/<id>`).
 *
 * The Admin GraphQL API identifies every object by a GID rather than a bare
 * numeric id, so the SDK accepts either and normalises to a GID on the way out.
 */
final class Gid
{
    public static function of(string $type, int|string $id): string
    {
        return "gid://shopify/{$type}/{$id}";
    }

    /**
     * Coerce a numeric id (or an already-formed GID) to a Product GID.
     */
    public static function product(int|string $id): string
    {
        return self::isGid((string) $id) ? (string) $id : self::of('Product', $id);
    }

    public static function customer(int|string $id): string
    {
        return self::isGid((string) $id) ? (string) $id : self::of('Customer', $id);
    }

    public static function order(int|string $id): string
    {
        return self::isGid((string) $id) ? (string) $id : self::of('Order', $id);
    }

    public static function collection(int|string $id): string
    {
        return self::isGid((string) $id) ? (string) $id : self::of('Collection', $id);
    }

    public static function priceList(int|string $id): string
    {
        return self::isGid((string) $id) ? (string) $id : self::of('PriceList', $id);
    }

    public static function fulfillmentOrder(int|string $id): string
    {
        return self::isGid((string) $id) ? (string) $id : self::of('FulfillmentOrder', $id);
    }

    public static function draftOrder(int|string $id): string
    {
        return self::isGid((string) $id) ? (string) $id : self::of('DraftOrder', $id);
    }

    public static function variant(int|string $id): string
    {
        return self::isGid((string) $id) ? (string) $id : self::of('ProductVariant', $id);
    }

    public static function giftCard(int|string $id): string
    {
        return self::isGid((string) $id) ? (string) $id : self::of('GiftCard', $id);
    }

    public static function webhookSubscription(int|string $id): string
    {
        return self::isGid((string) $id) ? (string) $id : self::of('WebhookSubscription', $id);
    }

    /**
     * Extract the trailing numeric id from a GID (`gid://shopify/Product/123` → `123`).
     */
    public static function id(string $gid): string
    {
        $path  = (string) (parse_url($gid, PHP_URL_PATH) ?? '');
        $parts = explode('/', trim($path, '/'));

        return end($parts) ?: $gid;
    }

    public static function isGid(string $value): bool
    {
        return str_starts_with($value, 'gid://shopify/');
    }
}
