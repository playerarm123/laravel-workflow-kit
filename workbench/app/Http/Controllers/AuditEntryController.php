<?php

namespace App\Http\Controllers;

use App\Application\Audit\UseCases\ListAuditEntries\ListAuditEntriesCommand;
use App\Application\Audit\UseCases\ListAuditEntries\ListAuditEntriesHandler;
use App\Models\AuditEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit log, read only (audit-log.md): an index and nothing else.
 */
class AuditEntryController extends Controller
{
    public function index(Request $request, ListAuditEntriesHandler $listAuditEntries): Response
    {
        Gate::authorize('viewAny', AuditEntry::class);

        $result = $listAuditEntries(new ListAuditEntriesCommand(
            sort: $request->string('sort')->toString(),
            direction: $request->string('direction')->toString(),
            search: $request->string('search')->toString(),
            subjectType: $request->string('subject_type')->toString(),
            createdFrom: $request->string('created_from')->toString(),
            createdTo: $request->string('created_to')->toString(),
            perPage: $request->string('per_page')->toString(),
        ));

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
