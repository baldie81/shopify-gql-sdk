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
 *
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/inventorySetQuantities Shopify Admin GraphQL: inventorySetQuantities
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/inventoryAdjustQuantities Shopify Admin GraphQL: inventoryAdjustQuantities
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/inventoryActivate Shopify Admin GraphQL: inventoryActivate
 */
final class Inventory extends AbstractResource
{
    /** Shopify caps inventory set/adjust at 250 quantities/changes per call. */
    private const MAX_QUANTITIES = 250;

    /**
     * Store locations (id, name, whether active / ships inventory).
     *
     * @return list<array<string,mixed>>
     */
    public function locations(?int $first = null): array
    {
        $query = 'query ($first: Int!, $after: String) { locations(first: $first, after: $after) {'
            . ' nodes { id name isActive shipsInventory } pageInfo { hasNextPage endCursor } } }';

        return $this->paginateNodes($query, [], 'locations', limit: $first);
    }

    /**
     * Set absolute quantities (overwrites). Each entry is
     * `['inventoryItemId' => …, 'locationId' => …, 'quantity' => N]`. Add
     * `'changeFromQuantity' => M` to an entry to make it a compare-and-swap (the
     * write is rejected unless the current quantity is M); without it the set is
     * unconditional.
     *
     * @param list<array<string,mixed>> $quantities
     * @return array{inventoryAdjustmentGroup:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function setQuantities(array $quantities, string $name = 'available', string $reason = 'correction', ?string $idempotencyKey = null): array
    {
        [$idem, $varDef, $directive] = $this->idempotencyParts();

        $mutation = 'mutation ($input: InventorySetQuantitiesInput!' . $varDef . ') {'
            . ' inventorySetQuantities(input: $input)' . $directive . ' {'
            . ' inventoryAdjustmentGroup { reason changes { name delta quantityAfterChange } } userErrors { field message code } } }';

        $base       = ['name' => $name, 'reason' => $reason];
        $quantities = array_values($quantities);
        if ($this->gql->apiVersionAtLeast('2026-01')) {
            // `changeFromQuantity` arrived in 2026-01 and is mandatory from
            // 2026-04. An explicit null skips the compare-and-swap check ("set to
            // N, whatever it was"); a caller-supplied value is kept as a guard.
            $quantities = array_map(
                static fn (array $q): array => array_key_exists('changeFromQuantity', $q) ? $q : $q + ['changeFromQuantity' => null],
                $quantities,
            );
        } else {
            // Before 2026-01: bypass the compare check the old way (the flag is
            // deprecated in 2026-01 and gone in 2026-04).
            $base['ignoreCompareQuantity'] = true;
        }

        return $this->bulk($mutation, 'inventorySetQuantities', 'quantities', $quantities, $base, $idem, $idempotencyKey);
    }

    /**
     * Adjust quantities by deltas. Each entry is
     * `['inventoryItemId' => …, 'locationId' => …, 'delta' => ±N]`. Add
     * `'changeFromQuantity' => M` to an entry to apply it only if the current
     * quantity is M; without it the delta is applied whatever the quantity is.
     *
     * @param list<array<string,mixed>> $changes
     * @return array{inventoryAdjustmentGroup:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function adjustQuantities(array $changes, string $name = 'available', string $reason = 'correction', ?string $idempotencyKey = null): array
    {
        [$idem, $varDef, $directive] = $this->idempotencyParts();

        $mutation = 'mutation ($input: InventoryAdjustQuantitiesInput!' . $varDef . ') {'
            . ' inventoryAdjustQuantities(input: $input)' . $directive . ' {'
            . ' inventoryAdjustmentGroup { reason changes { name delta } } userErrors { field message code } } }';

        $changes = array_values($changes);
        if ($this->gql->apiVersionAtLeast('2026-01')) {
            // As in setQuantities(): the field exists from 2026-01 and must be
            // sent from 2026-04, an explicit null meaning "no compare check".
            $changes = array_map(
                static fn (array $c): array => array_key_exists('changeFromQuantity', $c) ? $c : $c + ['changeFromQuantity' => null],
                $changes,
            );
        }

        return $this->bulk($mutation, 'inventoryAdjustQuantities', 'changes', $changes, ['name' => $name, 'reason' => $reason], $idem, $idempotencyKey);
    }

    /**
     * Run a set/adjust mutation, chunking the entries at {@see MAX_QUANTITIES}.
     * userErrors are aggregated; `inventoryAdjustmentGroup` reflects the last chunk.
     * When idempotency is required (2026-04+), each chunk gets a distinct key — a
     * shared key would make Shopify drop later chunks as duplicates. A caller's
     * key is sent as given when everything fits in one call, and with a
     * `-0`, `-1`, … suffix per call otherwise.
     *
     * @param list<array<string,mixed>> $entries
     * @param array<string,mixed>       $baseInput
     * @return array{inventoryAdjustmentGroup:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    private function bulk(string $mutation, string $field, string $entriesKey, array $entries, array $baseInput, bool $idem, ?string $idempotencyKey): array
    {
        $merged = ['inventoryAdjustmentGroup' => null, 'userErrors' => []];
        $chunks = array_chunk(array_values($entries), self::MAX_QUANTITIES);
        foreach ($chunks as $i => $chunk) {
            $variables = ['input' => $baseInput + [$entriesKey => $chunk]];
            if ($idem) {
                $variables['idempotencyKey'] = match (true) {
                    $idempotencyKey === null => $this->newIdempotencyKey(),
                    count($chunks) === 1     => $idempotencyKey,
                    default                  => "{$idempotencyKey}-{$i}",
                };
            }
            $result = $this->gql->execute($mutation, $variables)[$field] ?? [];
            if (isset($result['inventoryAdjustmentGroup'])) {
                $merged['inventoryAdjustmentGroup'] = $result['inventoryAdjustmentGroup'];
            }
            foreach ($result['userErrors'] ?? [] as $error) {
                $merged['userErrors'][] = $error;
            }
        }

        return $merged;
    }

    /**
     * Stock an inventory item at a location (`inventoryActivate`), optionally
     * seeding an available quantity.
     *
     * @return array{inventoryLevel:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    public function activate(string $inventoryItemId, string $locationId, ?int $available = null, ?string $idempotencyKey = null): array
    {
        [$idem, $varDef, $directive] = $this->idempotencyParts();

        $mutation = 'mutation ($inventoryItemId: ID!, $locationId: ID!, $available: Int' . $varDef . ') {'
            . ' inventoryActivate(inventoryItemId: $inventoryItemId, locationId: $locationId, available: $available)' . $directive . ' {'
            . ' inventoryLevel { id } userErrors { field message } } }';

        $variables = ['inventoryItemId' => $inventoryItemId, 'locationId' => $locationId, 'available' => $available];
        if ($idem) {
            $variables['idempotencyKey'] = $idempotencyKey ?? $this->newIdempotencyKey();
        }

        return $this->gql->execute($mutation, $variables)['inventoryActivate'] ?? ['inventoryLevel' => null, 'userErrors' => []];
    }
}
