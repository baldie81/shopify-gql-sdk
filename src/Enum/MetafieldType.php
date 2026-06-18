<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Enum;

/**
 * Common Shopify metafield types. The full set is open and evolves, so
 * {@see \Baldie81\ShopifyGraphQL\Support\Metafield} also accepts a raw type
 * string for anything not listed here.
 */
enum MetafieldType: string
{
    case SingleLineText = 'single_line_text_field';
    case MultiLineText  = 'multi_line_text_field';
    case NumberInteger  = 'number_integer';
    case NumberDecimal  = 'number_decimal';
    case Boolean        = 'boolean';
    case Json           = 'json';
    case Date           = 'date';
    case DateTime       = 'date_time';
    case Money          = 'money';
    case Dimension      = 'dimension';
    case Weight         = 'weight';
    case Volume         = 'volume';
    case Rating         = 'rating';
    case Color          = 'color';
    case Url            = 'url';
    case RichText       = 'rich_text_field';

    case ProductReference     = 'product_reference';
    case VariantReference     = 'variant_reference';
    case CollectionReference  = 'collection_reference';
    case PageReference        = 'page_reference';
    case FileReference        = 'file_reference';
    case MetaobjectReference  = 'metaobject_reference';

    // List variants (value must be a JSON array).
    case ListSingleLineText   = 'list.single_line_text_field';
    case ListProductReference = 'list.product_reference';
    case ListFileReference    = 'list.file_reference';
    case ListUrl              = 'list.url';
}
