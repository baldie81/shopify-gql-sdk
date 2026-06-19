<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

/**
 * Inventory: locations and quantities. Quantities are keyed by
 * (inventoryItemId, locationId) — get the inventoryItemId from a variant's
 * `inventoryItem.id` ({@see Variants::list()}) and the locationId from
 * {@see locations()}. The item must be *tracked* (set via
 * {@see Variants::update()} → `tracked`) and stocked at the location
 * ({@see activate()}) before a quantity sticks.
 */
final class Inventory extends AbstractResource
{
    /**
     * Store locations (id, name, whether active / ships inventory).
     *
     * @return list<array<string,mixed>>
     */
    public function locations(int $first = 50): array
    {
        $query = 'query ($first: Int!) { locations(first: $first) { nodes { id name isActive shipsInventory } } }';

        return $this->gql->execute($query, ['first' => $first])['locations']['nodes'] ?? [];
    }

    /**
     * Set absolute quantities (overwrites). Each entry is
     * `['inventoryItemId' => …, 'locationId' => …, 'quantity' => N]`.
     *
     * @param list<array<string,mixed>> $quantities
     * @return array{inventoryAdjustmentGroup:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function setQuantities(array $quantities, string $name = 'available', string $reason = 'correction'): array
    {
        $mutation = 'mutation ($input: InventorySetQuantitiesInput!) {'
            . ' inventorySetQuantities(input: $input) {'
            . ' inventoryAdjustmentGroup { reason changes { name delta quantityAfterChange } } userErrors { field message code } } }';

        $input = [
            'name'                  => $name,
            'reason'                => $reason,
            'ignoreCompareQuantity' => true,
            'quantities'            => array_values($quantities),
        ];

        return $this->gql->execute($mutation, ['input' => $input])['inventorySetQuantities']
            ?? ['inventoryAdjustmentGroup' => null, 'userErrors' => []];
    }

    /**
     * Adjust quantities by deltas. Each entry is
     * `['inventoryItemId' => …, 'locationId' => …, 'delta' => ±N]`.
     *
     * @param list<array<string,mixed>> $changes
     * @return array{inventoryAdjustmentGroup:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function adjustQuantities(array $changes, string $name = 'available', string $reason = 'correction'): array
    {
        $mutation = 'mutation ($input: InventoryAdjustQuantitiesInput!) {'
            . ' inventoryAdjustQuantities(input: $input) {'
            . ' inventoryAdjustmentGroup { reason changes { name delta } } userErrors { field message code } } }';

        $input = ['name' => $name, 'reason' => $reason, 'changes' => array_values($changes)];

        return $this->gql->execute($mutation, ['input' => $input])['inventoryAdjustQuantities']
            ?? ['inventoryAdjustmentGroup' => null, 'userErrors' => []];
    }

    /**
     * Stock an inventory item at a location (`inventoryActivate`), optionally
     * seeding an available quantity.
     *
     * @return array{inventoryLevel:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function activate(string $inventoryItemId, string $locationId, ?int $available = null): array
    {
        $mutation = 'mutation ($inventoryItemId: ID!, $locationId: ID!, $available: Int) {'
            . ' inventoryActivate(inventoryItemId: $inventoryItemId, locationId: $locationId, available: $available) {'
            . ' inventoryLevel { id } userErrors { field message } } }';

        return $this->gql->execute($mutation, [
            'inventoryItemId' => $inventoryItemId,
            'locationId'      => $locationId,
            'available'       => $available,
        ])['inventoryActivate'] ?? ['inventoryLevel' => null, 'userErrors' => []];
    }
}
