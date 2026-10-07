<?php

namespace App\Models\Concerns;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * Keys a model on the uuid `IdGenerator` mints (models.md). Unlike `HasUuids` it never fills a
 * missing id, so a write that forgot one fails at the insert. A route value that is not a uuid
 * is a 404 before it reaches the query, which a uuid column would refuse as a 500.
 *
 * @mixin Model
 */
trait KeyedByUuid
{
    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    /**
     * @param  Model|Builder|Relation<*, *, *>  $query
     * @param  mixed  $value
     * @param  string|null  $field
     *
     * @throws ModelNotFoundException<Model>
     */
    public function resolveRouteBindingQuery($query, $value, $field = null): Builder
    {
        if (($field ?? $this->getRouteKeyName()) === $this->getKeyName() && ! (is_string($value) && Str::isUuid($value))) {
            throw (new ModelNotFoundException)->setModel(static::class, [$value]);
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }
}
