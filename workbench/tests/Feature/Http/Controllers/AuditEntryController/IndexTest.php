<?php

use App\Models\AuditEntry;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * `auditLogReader()` and `auditLogOutsider()` come from tests/Pest.php: who may read the log
 * is the project's own policy (audit-log.md), so the kit never names a role.
 */

test('guests are redirected to the login page', function () {
    $this->get(route('audit-entries.index'))
        ->assertRedirect(route('login'));
});

test('a reader the policy allows reads the log with its sort, filters and subject types', function () {
    AuditEntry::factory()->about('invoice', 'i-1', 'invoice.issued')->create();
    AuditEntry::factory()->about('payment', 'p-1', 'payment.received')->create();
    AuditEntry::factory()->about('payment', 'p-2', 'payment.refunded')->create();

    $this->actingAs(auditLogReader())
        ->get(route('audit-entries.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('audit-entries/index')
            ->has('auditEntries.data', 3)
            ->where('sort', ['column' => 'occurred_at', 'direction' => 'desc'])
            ->where('filters.subject_type', null)
            ->where('filters.created_from', null)
            ->where('subjectTypes', ['invoice', 'payment']),
        );
});

test('the filters in the query string reach the list', function () {
    AuditEntry::factory()->about('invoice', 'i-1', 'invoice.issued')->create();
    AuditEntry::factory()->about('payment', 'p-1', 'payment.received')->create();

    $this->actingAs(auditLogReader())
        ->get(route('audit-entries.index', ['subject_type' => 'invoice', 'search' => 'i-']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('auditEntries.data', 1)
            ->where('auditEntries.data.0.subject_id', 'i-1')
            ->where('filters.subject_type', 'invoice')
            ->where('filters.search', 'i-'),
        );
});

test('a user the policy refuses is forbidden', function () {
    $this->actingAs(auditLogOutsider())
        ->get(route('audit-entries.index'))
        ->assertForbidden();
});
