<?php

namespace App\Http\Controllers;

use App\Application\Catalog\UseCases\ListProducts\ListProductsCommand;
use App\Application\Catalog\UseCases\ListProducts\ListProductsHandler;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The workbench's one HTTP resource: a list page and a form page the structure tooling reads.
 */
class ProductController extends Controller
{
    public function index(ListProductsHandler $listProducts): Response
    {
        $listProducts(new ListProductsCommand);

        return Inertia::render('products/index');
    }

    public function create(): Response
    {
        return Inertia::render('products/create');
    }
}
