<?php

use App\Domain\Shared\DomainEntity;
use App\Domain\Shared\Exceptions\EntityNotFoundException;
use App\Domain\Shared\Exceptions\RepositoryException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../../../../../vendor/playerarm123/laravel-workflow-kit/tests/Architecture/Support/rules.php';

/**
 * The cases every Eloquent repository must pass (repositories.md), registered
 * with their fixed titles so the workflow kit's tests/Architecture/RepositoriesTest can
 * find them. A repository test file calls this once and adds only what is specific to its
 * aggregate.
 *
 * - make: a valid, never-saved aggregate, with every row it points at already in place
 * - change: alter a persisted field through the aggregate's own behaviour
 * - unwritable: an aggregate the database refuses (a missing parent, a taken unique value)
 * - corrupt: damage the saved row so it can no longer be rebuilt into an entity
 * - read: how to read it back, when the repository has no findById()
 * - same: how to compare, when toEqual() is too strict (timestamps stored to the second)
 *
 * @template TEntity of DomainEntity
 *
 * @param  class-string  $repository  the domain interface, resolved from the container
 * @param  class-string<Model>  $model
 * @param  Closure(): TEntity  $make
 * @param  Closure(TEntity): void  $change
 * @param  Closure(): TEntity  $unwritable
 * @param  Closure(TEntity): void  $corrupt
 * @param  (Closure(object, TEntity): ?TEntity)|null  $read
 * @param  (Closure(TEntity, TEntity): void)|null  $same
 */
function repositoryContract(
    string $repository,
    string $model,
    Closure $make,
    Closure $change,
    Closure $unwritable,
    Closure $corrupt,
    ?Closure $read = null,
    ?Closure $same = null,
): void {
    $read ??= fn (object $repo, DomainEntity $entity): ?DomainEntity => $repo->findById($entity->id());
    $same ??= fn (DomainEntity $expected, DomainEntity $actual) => expect($actual)->toEqual($expected);

    describe('repository contract', function () use ($repository, $model, $make, $change, $unwritable, $corrupt, $read, $same) {
        it('reads back what it saved, field for field', function () use ($repository, $make, $read, $same) {
            $repo = app($repository);
            $entity = $make();

            $repo->save($entity);

            $same($entity, $read($repo, $entity));
        });

        it('writes over an existing row instead of failing like a plain insert', function () use ($repository, $model, $make, $change, $read, $same) {
            $repo = app($repository);
            $entity = $make();
            $repo->save($entity);

            $change($entity);
            $repo->save($entity);

            expect($model::query()->whereKey($entity->id())->count())->toBe(1);
            $same($entity, $read($repo, $entity));
        });

        it('wraps a failed write in its repository exception', function () use ($repository, $unwritable) {
            $entity = $unwritable();

            expect(fn () => app($repository)->save($entity))->toThrow(
                fn (RepositoryException $e) => expect($e->getCode())->toBe(RepositoryException::SAVE_FAILED_CODE),
            );
        });

        it('wraps a row it cannot rebuild in a reconstitute failure', function () use ($repository, $make, $corrupt, $read) {
            $repo = app($repository);
            $entity = $make();
            $repo->save($entity);

            $corrupt($entity);

            expect(fn () => $read($repo, $entity))->toThrow(
                fn (RepositoryException $e) => expect($e->getCode())->toBe(RepositoryException::RECONSTITUTE_FAILED_CODE),
            );
        });

        it('updates an existing row without inserting a new one', function () use ($repository, $model, $make, $change, $read, $same) {
            $repo = app($repository);
            $entity = $make();
            $repo->save($entity);
            $rows = $model::query()->count();

            $change($entity);
            $repo->update($entity);

            expect($model::query()->count())->toBe($rows);
            $same($entity, $read($repo, $entity));
        });

        it('throws not found from update when the row no longer exists', function () use ($repository, $make) {
            $entity = $make();

            expect(fn () => app($repository)->update($entity))->toThrow(EntityNotFoundException::class);
        });

        if (method_exists($repository, 'getById')) {
            it('throws not found from getById for an unknown id', function () use ($repository) {
                expect(fn () => app($repository)->getById(Str::uuid7()->toString()))->toThrow(EntityNotFoundException::class);
            });
        }
    });
}

/**
 * A `corrupt` hook for any aggregate: drop a column toEntity() needs, so the stored row can no
 * longer be rebuilt. Postgres rolls the DDL back with the test's transaction. MySQL and MariaDB
 * commit it at once, so the next test migrates the database fresh instead.
 *
 * A hook that writes no DDL is faster on those engines: update the row to a value toEntity()
 * refuses, such as `DB::table($table)->update(['status' => 'unknown'])`.
 */
function dropRepositoryContractColumn(string $table, string $column): void
{
    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
    ruleForgetSchemaAfterDdl();
}
