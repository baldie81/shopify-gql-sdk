<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Enum;

/**
 * Media content type, mirroring the Shopify Admin GraphQL `MediaContentType` enum.
 */
enum MediaContentType: string
{
    case Image         = 'IMAGE';
    case Video         = 'VIDEO';
    case ExternalVideo = 'EXTERNAL_VIDEO';
    case Model3d       = 'MODEL_3D';
}
