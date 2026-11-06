<?php

namespace App\Http\Requests\Product;

use App\Application\Catalog\UseCases\ListProducts\ListProductsCommand;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The query string of the products list. It goes into the Command raw and unvalidated, so this
 * request declares no rules() (list-queries.md).
 */
class IndexProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Product::class);
    }

    public function toCommand(): ListProductsCommand
    {
        return new ListProductsCommand;
    }
}
