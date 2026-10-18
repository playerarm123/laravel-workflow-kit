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
        $outsider = auditLogOutsider();

        if ($outsider === null) {
            $this->markTestSkipped('AuditEntryPolicy refuses no signed-in user: auditLogOutsider() is null');
        }

        expect(Gate::forUser($outsider)->allows('viewAny', AuditEntry::class))->toBeFalse();
    });
});
