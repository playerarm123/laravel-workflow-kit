<?php

/**
 * `kit:install` runs on the project the suite runs on, which already holds every kit file as
 * the kit ships it, so it writes nothing. KitInstallerTest proves the writing on a project of
 * its own.
 */
describe('kit:install', function () {
    it('writes nothing into a project that already holds every kit file', function () {
        $this->artisan('kit:install')
            ->doesntExpectOutputToContain('written')
            ->doesntExpectOutputToContain('differs')
            ->assertSuccessful();
    });
});
