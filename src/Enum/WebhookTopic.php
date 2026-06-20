<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Enum;

/**
 * Common `WebhookSubscriptionTopic` values. This is a convenience subset — the
 * full list is large, so the Webhooks resource also accepts a raw topic string
 * for any topic not enumerated here.
 */
enum WebhookTopic: string
{
    case OrdersCreate     = 'ORDERS_CREATE';
    case OrdersUpdated    = 'ORDERS_UPDATED';
    case OrdersPaid       = 'ORDERS_PAID';
    case OrdersFulfilled  = 'ORDERS_FULFILLED';
    case OrdersCancelled  = 'ORDERS_CANCELLED';
    case OrdersDelete     = 'ORDERS_DELETE';

    case DraftOrdersCreate = 'DRAFT_ORDERS_CREATE';
    case DraftOrdersUpdate = 'DRAFT_ORDERS_UPDATE';

    case ProductsCreate   = 'PRODUCTS_CREATE';
    case ProductsUpdate   = 'PRODUCTS_UPDATE';
    case ProductsDelete   = 'PRODUCTS_DELETE';

    case CustomersCreate  = 'CUSTOMERS_CREATE';
    case CustomersUpdate  = 'CUSTOMERS_UPDATE';
    case CustomersDelete  = 'CUSTOMERS_DELETE';

    case InventoryLevelsUpdate = 'INVENTORY_LEVELS_UPDATE';
    case InventoryItemsUpdate  = 'INVENTORY_ITEMS_UPDATE';

    case FulfillmentsCreate = 'FULFILLMENTS_CREATE';
    case FulfillmentsUpdate = 'FULFILLMENTS_UPDATE';
    case RefundsCreate      = 'REFUNDS_CREATE';

    case CollectionsCreate = 'COLLECTIONS_CREATE';
    case CollectionsUpdate = 'COLLECTIONS_UPDATE';

    case AppUninstalled = 'APP_UNINSTALLED';
    case ShopUpdate     = 'SHOP_UPDATE';
}
