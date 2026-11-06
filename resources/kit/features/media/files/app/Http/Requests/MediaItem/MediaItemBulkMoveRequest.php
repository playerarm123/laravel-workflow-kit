<?php

namespace App\Http\Requests\MediaItem;

use App\Application\Media\UseCases\MoveMediaItems\MoveMediaItemsCommand;
use App\Models\MediaItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MediaItemBulkMoveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('moveAny', MediaItem::class);
    }

    /**
     * The selected images, at most one grid's worth of pages, and the folder they go to; no
     * folder takes them out of every folder.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['uuid', 'distinct', Rule::exists('media_items', 'id')],
            'folder_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ids' => __('media-items.images'),
            'ids.*' => __('media-items.image'),
            'folder_id' => __('media-items.folder'),
        ];
    }

    public function toCommand(): MoveMediaItemsCommand
    {
        return new MoveMediaItemsCommand(
            ids: $this->ids(),
            folderId: $this->filled('folder_id') ? $this->string('folder_id')->toString() : null,
        );
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        /** @var array<int, string> $ids */
        $ids = $this->validated('ids');

        return array_values($ids);
    }
}
