<?php

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

describe('KeyedByUuid', function () {
    it('keys the model on a string that never increments', function () {
        $user = new User;

        expect($user->getIncrementing())->toBeFalse()
            ->and($user->getKeyType())->toBe('string');
    });

    it('mints no id, so a write that forgot one fails at the insert', function () {
        $user = User::factory()->make(['id' => null]);

        expect(fn () => $user->save())->toThrow(QueryException::class);
    });

    describe('resolveRouteBinding', function () {
        it('binds the row its uuid names', function () {
            $user = User::factory()->create();

            expect((new User)->resolveRouteBinding($user->id)?->is($user))->toBeTrue();
        });

        it('finds nothing for a uuid no row has', function () {
            expect((new User)->resolveRouteBinding(fake()->uuid()))->toBeNull();
        });

        it('refuses a value that is not a uuid before it reaches the query', function () {
            expect(fn () => (new User)->resolveRouteBinding('999999'))->toThrow(ModelNotFoundException::class);
        });

        it('binds by another column without asking for a uuid', function () {
            $user = User::factory()->create(['username' => 'not-a-uuid']);

            expect((new User)->resolveRouteBinding('not-a-uuid', 'username')?->is($user))->toBeTrue();
        });
    });
});
