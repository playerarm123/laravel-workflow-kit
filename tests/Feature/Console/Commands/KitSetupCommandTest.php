<?php

/**
 * kit:setup edits the project it runs on, so the suite proves its steps on a copy of the starter
 * kit (Setup/ProjectSetupTest) and only the guard that stops it before any edit here.
 */
describe('kit:setup', function () {
    it('refuses a database the stack does not support before it touches anything', function () {
        $this->artisan('kit:setup', ['--database' => 'sqlite'])
            ->expectsOutputToContain('The database must be one of pgsql, mysql, mariadb, not [sqlite].')
            ->assertFailed();
    });
});
