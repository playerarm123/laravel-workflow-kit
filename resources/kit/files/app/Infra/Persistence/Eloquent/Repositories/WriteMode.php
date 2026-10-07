<?php

namespace App\Infra\Persistence\Eloquent\Repositories;

/**
 * How `EloquentRepository::write()` treats the root rows it is given.
 */
enum WriteMode
{
    /** `save()` — insert, or write over the row with the same id. */
    case Upsert;

    /** `update()` — the row must already exist; a missing row is NotFound, never an insert. */
    case Update;

    /** `clone()` — the row must not exist; an existing id is a failure, never an overwrite. */
    case Insert;
}
