<?php

namespace App\Http\Requests\MediaItem;

use App\Application\Media\UseCases\ChangeMediaItemInfos\ChangeMediaItemInfosCommand;
use App\Models\MediaItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;

class UpdateMediaItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->mediaItem());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'folder_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('media-items.name'),
            'alt_text' => __('media-items.alt_text'),
            'folder_id' => __('media-items.folder'),
        ];
    }

    public function toCommand(): ChangeMediaItemInfosCommand
    {
        return new ChangeMediaItemInfosCommand(
            mediaItemId: $this->mediaItem()->id,
            name: $this->string('name')->trim()->toString(),
            altText: $this->filled('alt_text') ? $this->string('alt_text')->trim()->toString() : null,
            folderId: $this->filled('folder_id') ? $this->string('folder_id')->toString() : null,
        );
    }

    private function mediaItem(): MediaItem
    {
        $mediaItem = $this->route('media_item');

        if (! $mediaItem instanceof MediaItem) {
            throw new LogicException('UpdateMediaItemRequest is bound only to routes with a {media_item} model.');
        }

        return $mediaItem;
    }
}
