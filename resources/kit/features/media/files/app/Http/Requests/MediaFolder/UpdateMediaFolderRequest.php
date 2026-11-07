<?php

namespace App\Http\Requests\MediaFolder;

use App\Application\Media\UseCases\RenameMediaFolder\RenameMediaFolderCommand;
use App\Models\MediaFolder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use LogicException;

class UpdateMediaFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('update', $this->mediaFolder());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['name' => __('media-folders.name')];
    }

    public function toCommand(): RenameMediaFolderCommand
    {
        return new RenameMediaFolderCommand(
            mediaFolderId: $this->mediaFolder()->id,
            name: $this->string('name')->trim()->toString(),
        );
    }

    private function mediaFolder(): MediaFolder
    {
        $mediaFolder = $this->route('media_folder');

        if (! $mediaFolder instanceof MediaFolder) {
            throw new LogicException('UpdateMediaFolderRequest is bound only to routes with a {media_folder} model.');
        }

        return $mediaFolder;
    }
}
