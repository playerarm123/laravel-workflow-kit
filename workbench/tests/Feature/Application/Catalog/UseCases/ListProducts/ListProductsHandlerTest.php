<?php

use App\Application\Catalog\UseCases\ListProducts\ListProductsHandler;

beforeEach(function () {
    $this->handler = app(ListProductsHandler::class);
});

describe('ListProductsHandler', function () {
    it('does what the use case is named after')->todo();
});
