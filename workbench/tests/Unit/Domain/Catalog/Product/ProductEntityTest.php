<?php

use App\Domain\Catalog\Product\ProductEntity;

describe('ProductEntity', function () {
    describe('create()', function () {
        it('builds the entity from valid attributes')->todo();

        it('refuses an attribute that breaks its rule')->todo();
    });

    describe('reconstitute()', function () {
        it('rebuilds the entity exactly as it was stored')->todo();
    });
});
