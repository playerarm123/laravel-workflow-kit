<?php

use App\Models\User;

/*
 * Who the image library's policies let in, for the kit's media tests, which never name a role.
 * Pest loads every file in tests/Helpers. The kit writes this file once: change it together with
 * MediaItemPolicy and MediaFolderPolicy.
 */

/**
 * A user the policies let see and change the library and its folders.
 */
function mediaLibraryManager(): User
{
    return User::factory()->create();
}

/**
 * A signed-in user the policies refuse everything. Return null when they let every signed-in user
 * in: the kit's tests that need somebody they refuse then skip.
 */
function mediaLibraryOutsider(): ?User
{
    return User::factory()->unverified()->create();
}
