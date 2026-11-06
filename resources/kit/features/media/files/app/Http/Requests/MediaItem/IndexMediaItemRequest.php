<?php

namespace App\Http\Requests\MediaItem;

use App\Application\Media\UseCases\ListMediaItems\ListMediaItemsCommand;
use App\Models\MediaItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The query string of the image library, which the library page and the picker both read. It goes
 * into the Command raw and unvalidated, so this request declares no rules(): the handler settles
 * every value, and a hand-edited url still gets a page, not a 422 (list-queries.md).
 */
class IndexMediaItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', MediaItem::class);
    }

    public function toCommand(): ListMediaItemsCommand
    {
        return new ListMediaItemsCommand(
            search: $this->string('search')->toString(),
            folder: $this->string('folder')->toString(),
            usage: $this->string('usage')->toString(),
            createdFrom: $this->string('created_from')->toString(),
            createdTo: $this->string('created_to')->toString(),
            perPage: $this->string('per_page')->toString(),
        );
    }
}
