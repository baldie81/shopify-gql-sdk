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
    /** Shopify caps inventory set/adjust at 250 quantities/changes per call. */
    private const MAX_QUANTITIES = 250;

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
    public function setQuantities(array $quantities, string $name = 'available', string $reason = 'correction', ?string $idempotencyKey = null): array
    {
        [$idem, $varDef, $directive] = $this->idempotencyParts();

        $mutation = 'mutation ($input: InventorySetQuantitiesInput!' . $varDef . ') {'
            . ' inventorySetQuantities(input: $input)' . $directive . ' {'
            . ' inventoryAdjustmentGroup { reason changes { name delta quantityAfterChange } } userErrors { field message code } } }';

        $base       = ['name' => $name, 'reason' => $reason];
        $quantities = array_values($quantities);
        if ($idem) {
            // 2026-04 removed unconditional sets: each entry needs changeFromQuantity
            // (compare-and-swap). Resolve the current value for any entry that
            // didn't supply one, so "set to N" still works.
            $quantities = $this->fillChangeFromQuantity($quantities, $name);
        } else {
            // Pre-2026-04: bypass the compare check.
            $base['ignoreCompareQuantity'] = true;
        }

        return $this->bulk($mutation, 'inventorySetQuantities', 'quantities', $quantities, $base, $idem, $idempotencyKey);
    }

    /**
     * Fill `changeFromQuantity` (the current value) for entries that omit it, by
     * reading the live quantities once. Caller-supplied `changeFromQuantity` (a
     * deliberate compare-and-swap guard) is left untouched.
     *
     * @param list<array<string,mixed>> $quantities
     * @return list<array<string,mixed>>
     */
    private function fillChangeFromQuantity(array $quantities, string $name): array
    {
        $itemIds = [];
        foreach ($quantities as $q) {
            if (!array_key_exists('changeFromQuantity', $q) && isset($q['inventoryItemId'])) {
                $itemIds[(string) $q['inventoryItemId']] = true;
            }
        }
        if ($itemIds === []) {
            return $quantities;
        }

        $current = $this->currentQuantities(array_keys($itemIds), $name);
        foreach ($quantities as &$q) {
            if (!array_key_exists('changeFromQuantity', $q)) {
                $q['changeFromQuantity'] = $current["{$q['inventoryItemId']}|{$q['locationId']}"] ?? 0;
            }
        }
        unset($q);

        return $quantities;
    }

    /**
     * Map of "inventoryItemId|locationId" => current quantity for the named state.
     *
     * @param list<string> $itemIds
     * @return array<string,int>
     */
    private function currentQuantities(array $itemIds, string $name): array
    {
        $query = 'query ($ids: [ID!]!, $name: String!) { nodes(ids: $ids) { ... on InventoryItem { id'
            . ' inventoryLevels(first: 50) { nodes { location { id } quantities(names: [$name]) { name quantity } } } } } }';

        $map = [];
        foreach (array_chunk($itemIds, self::MAX_QUANTITIES) as $chunk) {
            foreach ($this->gql->execute($query, ['ids' => $chunk, 'name' => $name])['nodes'] ?? [] as $node) {
                $item = $node['id'] ?? null;
                if ($item === null) {
                    continue;
                }
                foreach ($node['inventoryLevels']['nodes'] ?? [] as $level) {
                    $loc = $level['location']['id'] ?? null;
                    if ($loc === null) {
                        continue;
                    }
                    foreach ($level['quantities'] ?? [] as $q) {
                        if (($q['name'] ?? null) === $name) {
                            $map["{$item}|{$loc}"] = (int) $q['quantity'];
                        }
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Adjust quantities by deltas. Each entry is
     * `['inventoryItemId' => …, 'locationId' => …, 'delta' => ±N]`.
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

        return $this->bulk($mutation, 'inventoryAdjustQuantities', 'changes', $changes, ['name' => $name, 'reason' => $reason], $idem, $idempotencyKey);
    }

    /**
     * Run a set/adjust mutation, chunking the entries at {@see MAX_QUANTITIES}.
     * userErrors are aggregated; `inventoryAdjustmentGroup` reflects the last chunk.
     * When idempotency is required (2026-04+), each chunk gets a distinct key — a
     * shared key would make Shopify drop later chunks as duplicates.
     *
     * @param list<array<string,mixed>> $entries
     * @param array<string,mixed>       $baseInput
     * @return array{inventoryAdjustmentGroup:?array<string,mixed>,userErrors:list<array<string,mixed>>}
     */
    private function bulk(string $mutation, string $field, string $entriesKey, array $entries, array $baseInput, bool $idem, ?string $idempotencyKey): array
    {
        $merged = ['inventoryAdjustmentGroup' => null, 'userErrors' => []];
        foreach (array_chunk(array_values($entries), self::MAX_QUANTITIES) as $i => $chunk) {
            $variables = ['input' => $baseInput + [$entriesKey => $chunk]];
            if ($idem) {
                $variables['idempotencyKey'] = $idempotencyKey !== null ? "{$idempotencyKey}-{$i}" : $this->newIdempotencyKey();
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
