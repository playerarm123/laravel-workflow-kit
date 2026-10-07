<?php

namespace App\Domain\Shared;

abstract class DomainEntity
{
    abstract public function id(): string;

    abstract public static function entityName(): string;
}
