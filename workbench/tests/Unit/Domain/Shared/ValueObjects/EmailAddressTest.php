<?php

use App\Domain\Shared\ValueObjects\EmailAddress;

/**
 * @see EmailAddress
 */
describe('EmailAddress', function () {
    describe('from()', function () {
        it('holds the values it is built from')->todo();

        it('refuses a value that breaks its rule')->todo();
    });

    describe('equals()', function () {
        it('is equal to a value object built from the same values')->todo();

        it('differs from one built from other values')->todo();
    });
});
