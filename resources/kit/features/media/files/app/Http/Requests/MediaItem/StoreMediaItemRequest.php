<?php

namespace App\Http\Requests\MediaItem;

use App\Application\Media\UseCases\UploadMediaItems\UploadMediaItemsCommand;
use App\Models\MediaItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreMediaItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', MediaItem::class);
    }

    /**
     * Up to the configured number of jpg, png or webp images, each within the size and the
     * dimensions config allows, and the folder they go to, if any. A picture larger than the
     * dimensions is refused before it is decoded, which would take too much memory.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxDimension = (int) config('media.max_dimension');

        return [
            'images' => ['required', 'array', 'min:1', 'max:'.(int) config('media.max_files')],
            'images.*' => [
                'required',
                File::types(config('media.extensions'))->max((int) config('media.max_kb')),
                Rule::dimensions()->maxWidth($maxDimension)->maxHeight($maxDimension),
            ],
            'folder_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'images' => __('media-items.images'),
            'images.*' => __('media-items.image'),
            'folder_id' => __('media-items.folder'),
        ];
    }

    public function toCommand(): UploadMediaItemsCommand
    {
        /** @var list<UploadedFile> $images */
        $images = array_values($this->file('images', []));

        return new UploadMediaItemsCommand(
            folderId: $this->filled('folder_id') ? $this->string('folder_id')->toString() : null,
            images: $images,
        );
    }
}
