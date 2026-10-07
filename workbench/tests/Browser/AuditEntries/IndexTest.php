<?php

use App\Models\AuditEntry;

/**
 * `auditLogReader()` comes from tests/Pest.php: who may read the log is the project's own
 * policy (audit-log.md), so the kit never names a role.
 */
beforeEach(function () {
    $this->actor = auditLogReader();
    $this->actor->forceFill(['name' => 'Somchai Auditor'])->save();
    $this->actingAs($this->actor);
});

it('renders the log with the actor named and the system spelled out', function () {
    AuditEntry::factory()->about('invoice', 'invoice-0001', 'invoice.issued')->by($this->actor->id)->create(['occurred_at' => now()]);
    AuditEntry::factory()->about('report', 'report-0001', 'report.generated')->create(['occurred_at' => now()->subMinute()]);

    visit(route('audit-entries.index'))
        ->assertSee(__('audit-entries.events.invoice.issued'))
        ->assertSee('invoice.issued')
        ->assertSee(__('audit-entries.subject_types.invoice'))
        ->assertSee('Somchai Auditor')
        ->assertSee('report.generated')
        ->assertSee(__('audit-entries.system'))
        ->assertNoJavaScriptErrors();
});

it('narrows the log through the shared toolbar search and keeps the sort on the url', function () {
    AuditEntry::factory()->about('invoice', 'invoice-0001', 'invoice.issued')->create();
    AuditEntry::factory()->about('payment', 'payment-0001', 'payment.received')->create();

    visit(route('audit-entries.index', ['direction' => 'asc']))
        ->fill(sprintf('input[aria-label="%s"]', __('common.search')), 'invoice-')
        ->wait(1)
        ->assertQueryStringHas('search', 'invoice-')
        ->assertQueryStringHas('direction', 'asc')
        ->assertSee('invoice.issued')
        ->assertDontSee('payment.received')
        ->assertNoJavaScriptErrors();
});

it('opens an entry with its data in the view dialog', function () {
    AuditEntry::factory()->about('invoice', 'invoice-0001', 'invoice.issued')->create([
        'data' => ['amount' => '1500.00', 'source_id' => 'source-0001'],
    ]);

    visit(route('audit-entries.index'))
        ->click(sprintf('button[aria-label="%s"]', __('audit-entries.action_view')))
        ->assertSee(__('audit-entries.detail_title'))
        ->assertSee(__('audit-entries.data_keys.amount'))
        ->assertSee('1500.00')
        ->assertSee('source-0001')
        ->assertNoJavaScriptErrors();
});

it('still renders a page for a hand edited url', function () {
    visit(route('audit-entries.index', ['sort' => 'password', 'subject_type' => 'nothing', 'per_page' => 'all']))
        ->assertSee(__('audit-entries.no_results_title'))
        ->assertNoJavaScriptErrors();
});
