<?php

namespace App\Application\Audit\UseCases\ListAuditEntries;

use Spatie\LaravelData\Data;

final class ListAuditEntriesCommand extends Data
{
    public function __construct(
        public readonly string $sort = '',
        public readonly string $direction = '',
        public readonly string $search = '',
        public readonly string $subjectType = '',
        public readonly string $createdFrom = '',
        public readonly string $createdTo = '',
        public readonly string $perPage = '',
    ) {}
}
