<?php

use App\Application\Auth\UserContext;
use App\Http\Middleware\InitializeUserContext;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['web', InitializeUserContext::class])
        ->get('_user-context/actor', fn () => ['actor_id' => Context::get('actor_id')]);
});

/**
 * The actor rides along on every log entry the request writes, so an exception's own context
 * never has to carry it (exceptions.md).
 */
it('adds the signed in user to the context of every log', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/_user-context/actor')
        ->assertOk()
        ->assertExactJson(['actor_id' => $user->getAuthIdentifier()]);
});

it('adds no actor for a guest', function () {
    $this->getJson('/_user-context/actor')
        ->assertOk()
        ->assertExactJson(['actor_id' => null]);
});

it('binds the signed in user as the actor handlers read', function () {
    $user = User::factory()->create();

    Route::middleware(['web', InitializeUserContext::class])
        ->get('_user-context/id', fn () => ['id' => app(UserContext::class)->id()]);

    $this->actingAs($user)
        ->getJson('/_user-context/id')
        ->assertOk()
        ->assertExactJson(['id' => $user->getAuthIdentifier()]);
});
