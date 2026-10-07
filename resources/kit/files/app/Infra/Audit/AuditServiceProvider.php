<?php

namespace App\Infra\Audit;

use App\Application\Audit\AuditLog;
use App\Application\Audit\UseCases\ListAuditEntries\ListAuditEntriesQuery;
use App\Infra\Persistence\Eloquent\Queries\EloquentListAuditEntriesQuery;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        AuditLog::class => DatabaseAuditLog::class,
        ListAuditEntriesQuery::class => EloquentListAuditEntriesQuery::class,
    ];
}
