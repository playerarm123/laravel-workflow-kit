<?php

use App\Models\AuditEntry;
use Illuminate\Support\Facades\Gate;

/**
 * Each ability of AuditEntryPolicy, allowed and denied, asked the way a controller asks it.
 */
describe('viewAny', function () {
    it('allows a user who verified their email', function () {
        expect(Gate::forUser(auditLogReader())->allows('viewAny', AuditEntry::class))->toBeTrue();
    });

    it('denies a user who has not', function () {
        expect(Gate::forUser(auditLogOutsider())->allows('viewAny', AuditEntry::class))->toBeFalse();
    });
});
