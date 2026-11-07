<?php

namespace App\Http\Requests\MediaFolder;

use App\Application\Media\UseCases\CreateMediaFolder\CreateMediaFolderCommand;
use App\Models\MediaFolder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class StoreMediaFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('create', MediaFolder::class);
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

    public function toCommand(): CreateMediaFolderCommand
    {
        return new CreateMediaFolderCommand(
            name: $this->string('name')->trim()->toString(),
        );
    }
}
