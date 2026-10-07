<?php

namespace App\Application\Concerns;

/**
 * The one shape every list Criteria hands its read port: the sorted column and which way.
 *
 * This is a trait and not a base class on purpose. Each Criteria types `$sort` as its own
 * `{Aggregate}ListSort` enum, and a parent that declared the property would have to type it
 * `BackedEnum` for all of them — PHP property types are invariant, so no child may narrow it
 * back, and every adapter would receive a `BackedEnum` where it expects its own enum. A trait
 * is copied into each class instead, so `$this->sort` keeps the concrete type it was declared
 * with. The two properties read here are the ones every Criteria already promotes.
 */
trait SortsAList
{
    /**
     * @return array{column: string, direction: 'asc'|'desc'}
     */
    public function toSort(): array
    {
        return [
            'column' => $this->sort->value,
            'direction' => $this->direction,
        ];
    }
}
