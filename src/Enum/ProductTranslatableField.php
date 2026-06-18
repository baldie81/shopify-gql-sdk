<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Enum;

/**
 * The translatable content keys for a Product. Note these are Shopify's internal
 * keys, which differ from the GraphQL field names — most notably the description
 * is `body_html`, not `descriptionHtml`.
 *
 * The first four are the core product fields; the two SEO fields are translatable
 * only when customised (Shopify won't translate SEO that defaults to title/body).
 * This enum is a convenience — the authoritative, version-correct set for a given
 * product is whatever {@see \Baldie81\ShopifyGraphQL\Resource\Translations::translatableContent()}
 * reports.
 */
enum ProductTranslatableField: string
{
    case Title          = 'title';
    case Description    = 'body_html';
    case Handle         = 'handle';
    case ProductType    = 'product_type';
    case SeoTitle       = 'meta_title';
    case SeoDescription = 'meta_description';
}
