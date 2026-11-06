<?php

namespace App\Http\Controllers;

use App\Application\Audit\UseCases\ListAuditEntries\ListAuditEntriesHandler;
use App\Http\Requests\AuditEntry\IndexAuditEntryRequest;
use App\Models\AuditEntry;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit log, read only (audit-log.md): an index and nothing else.
 */
class AuditEntryController extends Controller
{
    public function index(IndexAuditEntryRequest $request, ListAuditEntriesHandler $listAuditEntries): Response
    {
        $result = $listAuditEntries($request->toCommand());

        return Inertia::render('audit-entries/index', [
            'auditEntries' => $result->auditEntries,
            'sort' => $result->sort,
            'filters' => $result->filters,
            'subjectTypes' => $this->subjectTypes(),
        ]);
    }

    /**
     * The options of the subject type filter: every type the log has recorded so far, so a
     * new aggregate appears in the filter the day its first entry is written. Read straight
     * off the model, because it is a lookup for the toolbar, not part of the list.
     *
     * @return list<string>
     */
    private function subjectTypes(): array
    {
        return array_values(AuditEntry::query()
            ->select('subject_type')
            ->distinct()
            ->orderBy('subject_type')
            ->get()
            ->map(fn (AuditEntry $entry): string => $entry->subject_type)
            ->all());
    }
}
