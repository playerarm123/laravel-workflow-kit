<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature and Browser tests run on Laravel and a fresh database. Unit tests stay plain PHP, so a
| domain class that starts to need the framework shows up as a failing Unit test (testing.md).
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Browser');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * A user AuditEntryPolicy lets read the audit log. The kit's audit log tests sign in through it,
 * so they never name a role: change it together with the policy.
 */
function auditLogReader(): User
{
    return User::factory()->create();
}

/**
 * A signed-in user AuditEntryPolicy refuses.
 */
function auditLogOutsider(): User
{
    return User::factory()->unverified()->create();
}
