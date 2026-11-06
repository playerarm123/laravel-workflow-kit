<?php

namespace App\Http\Requests\AuditEntry;

use App\Application\Audit\UseCases\ListAuditEntries\ListAuditEntriesCommand;
use App\Models\AuditEntry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The query string of the audit-entries list. It goes into the Command raw and unvalidated, so
 * this request declares no rules(): the handler settles every value, and a hand-edited url
 * still gets a page, not a 422 (list-queries.md).
 */
class IndexAuditEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', AuditEntry::class);
    }

    public function toCommand(): ListAuditEntriesCommand
    {
        return new ListAuditEntriesCommand(
            sort: $this->string('sort')->toString(),
            direction: $this->string('direction')->toString(),
            search: $this->string('search')->toString(),
            subjectType: $this->string('subject_type')->toString(),
            createdFrom: $this->string('created_from')->toString(),
            createdTo: $this->string('created_to')->toString(),
            perPage: $this->string('per_page')->toString(),
        );
    }
}
