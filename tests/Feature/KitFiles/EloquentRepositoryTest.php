<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories\BinEntity;
use Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories\BinRepository;
use Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories\CrateEntity;
use Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories\CrateItemEntity;
use Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories\CrateRepository;
use Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories\FixtureNotFoundException;
use Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories\FixtureRepositoryException;
use Playerarm123\LaravelWorkflowKit\Tests\Fixtures\Repositories\TrashableChildCrateRepository;

/**
 * The kit's `app/Infra/Persistence/Eloquent/Repositories/EloquentRepository.php`: what
 * repositories.md promises about every write, proven on the real engine rather than read off the
 * code. The fixtures in tests/Fixtures/Repositories are a crate with owned items and a
 * soft-deletable bin. The MySQL/MariaDB fallback needs those engines, which this suite does not run.
 */
beforeEach(function () {
    Schema::create('kit_crates', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('label')->unique();
        $table->timestamps();
    });

    Schema::create('kit_crate_items', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->foreignUuid('crate_id')->constrained('kit_crates')->cascadeOnDelete();
        $table->string('sku');
        $table->timestamps();
        $table->softDeletes();
        $table->unique(['crate_id', 'sku']);
    });

    Schema::create('kit_bins', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->string('label');
        $table->timestamps();
        $table->softDeletes();
    });
});

function kitCrate(string $label, string ...$skus): CrateEntity
{
    return new CrateEntity(
        (string) Str::uuid7(),
        $label,
        array_map(fn (string $sku): CrateItemEntity => new CrateItemEntity((string) Str::uuid7(), $sku), $skus),
    );
}

/**
 * @return list<string>
 */
function kitSkusOf(CrateEntity $crate): array
{
    return array_map(fn (CrateItemEntity $item): string => $item->sku, $crate->items);
}

describe('saveEntity', function () {
    it('inserts a new aggregate with its children and reads it back', function () {
        $repository = new CrateRepository;
        $crate = kitCrate('A-1', 'apple', 'pear');

        $repository->save($crate);

        $read = $repository->getById($crate->id());

        expect($read->label)->toBe('A-1')
            ->and(kitSkusOf($read))->toBe(['apple', 'pear']);
    });

    it('writes over the row with the same id, moves updated_at and never created_at', function () {
        $repository = new CrateRepository;
        $crate = kitCrate('A-1');

        $this->travelTo(now()->subDay()->startOfSecond());
        $repository->save($crate);
        $created = DB::table('kit_crates')->value('created_at');

        $this->travelBack();
        $repository->save(new CrateEntity($crate->id(), 'A-2'));

        $row = DB::table('kit_crates')->where('id', $crate->id())->first();

        expect(DB::table('kit_crates')->count())->toBe(1)
            ->and($row->label)->toBe('A-2')
            ->and($row->created_at)->toBe($created)
            ->and($row->updated_at)->not->toBe($created);
    });

    it('lands a batch whole or not at all, and wraps the refusal as saveFailed', function () {
        $repository = new CrateRepository;
        $repository->save(kitCrate('taken'));

        $fresh = kitCrate('fresh', 'apple');

        expect(fn () => $repository->saveMany([$fresh, kitCrate('taken')]))
            ->toThrow(FixtureRepositoryException::class);

        expect(DB::table('kit_crates')->where('id', $fresh->id())->exists())->toBeFalse()
            ->and(DB::table('kit_crate_items')->count())->toBe(0);
    });
});

describe('updateEntity', function () {
    it('rewrites a row that exists', function () {
        $repository = new CrateRepository;
        $crate = kitCrate('A-1');
        $repository->save($crate);

        $repository->update(new CrateEntity($crate->id(), 'A-2'));

        expect($repository->getById($crate->id())->label)->toBe('A-2');
    });

    it('throws not found for a missing row and rolls the whole batch back', function () {
        $repository = new CrateRepository;
        $crate = kitCrate('A-1');
        $repository->save($crate);

        expect(fn () => $repository->updateMany([new CrateEntity($crate->id(), 'A-2'), kitCrate('missing')]))
            ->toThrow(FixtureNotFoundException::class);

        expect($repository->getById($crate->id())->label)->toBe('A-1')
            ->and(DB::table('kit_crates')->count())->toBe(1);
    });
});

describe('a handler transaction around the repository', function () {
    it('takes a write that already landed back with it when a later step fails', function () {
        $repository = new CrateRepository;
        $crate = kitCrate('A-1', 'apple');

        expect(fn () => DB::transaction(function () use ($repository, $crate) {
            $repository->save($crate);

            throw new RuntimeException('a later step of the use case');
        }))->toThrow(RuntimeException::class, 'a later step of the use case');

        expect(DB::table('kit_crates')->count())->toBe(0)
            ->and(DB::table('kit_crate_items')->count())->toBe(0);
    });
});

describe('syncChildren', function () {
    it('deletes the children the aggregate no longer holds, then writes the rest', function () {
        $repository = new CrateRepository;
        $crate = kitCrate('A-1', 'apple', 'pear');
        $repository->save($crate);

        $replacement = new CrateItemEntity((string) Str::uuid7(), 'pear');
        $repository->update(new CrateEntity($crate->id(), 'A-1', [$replacement]));

        $read = $repository->getById($crate->id());

        expect(kitSkusOf($read))->toBe(['pear'])
            ->and($read->items[0]->id())->toBe($replacement->id())
            ->and(DB::table('kit_crate_items')->count())->toBe(1);
    });

    it('refuses a child model that uses SoftDeletes, and writes nothing', function () {
        $repository = new TrashableChildCrateRepository;
        $crate = kitCrate('A-1', 'apple');

        expect(fn () => $repository->save($crate))
            ->toThrow(LogicException::class, 'uses SoftDeletes');

        expect(DB::table('kit_crates')->count())->toBe(0)
            ->and(DB::table('kit_crate_items')->count())->toBe(0);
    });
});

describe('deleteEntity', function () {
    it('deletes the row and everything the aggregate owns', function () {
        $repository = new CrateRepository;
        $crate = kitCrate('A-1', 'apple');
        $repository->save($crate);

        $repository->delete($crate);

        expect(DB::table('kit_crates')->count())->toBe(0)
            ->and(DB::table('kit_crate_items')->count())->toBe(0);
    });

    it('throws not found when the row no longer exists', function () {
        expect(fn () => (new CrateRepository)->delete(kitCrate('missing')))
            ->toThrow(FixtureNotFoundException::class);
    });
});

describe('soft delete', function () {
    it('stamps deleted_at so no read finds the row, and update() treats it as missing', function () {
        $repository = new BinRepository;
        $bin = new BinEntity((string) Str::uuid7(), 'B-1');
        $repository->save($bin);

        $repository->delete($bin);

        expect($repository->findById($bin->id()))->toBeNull()
            ->and(DB::table('kit_bins')->whereNotNull('deleted_at')->count())->toBe(1)
            ->and(fn () => $repository->update($bin))->toThrow(FixtureNotFoundException::class);
    });

    it('never clears deleted_at on save()', function () {
        $repository = new BinRepository;
        $bin = new BinEntity((string) Str::uuid7(), 'B-1');
        $repository->save($bin);
        $repository->delete($bin);

        $repository->save(new BinEntity($bin->id(), 'B-2'));

        expect($repository->findById($bin->id()))->toBeNull()
            ->and(DB::table('kit_bins')->value('label'))->toBe('B-2');
    });

    it('restores a soft deleted row, and only a soft deleted one', function () {
        $repository = new BinRepository;
        $bin = new BinEntity((string) Str::uuid7(), 'B-1');
        $repository->save($bin);
        $repository->delete($bin);

        $repository->restore($bin->id());

        expect($repository->findById($bin->id()))->not->toBeNull()
            ->and(fn () => $repository->restore($bin->id()))->toThrow(FixtureNotFoundException::class);
    });

    it('purges a soft deleted row for good', function () {
        $repository = new BinRepository;
        $bin = new BinEntity((string) Str::uuid7(), 'B-1');
        $repository->save($bin);
        $repository->delete($bin);

        $repository->purge($bin);

        expect(DB::table('kit_bins')->count())->toBe(0)
            ->and(fn () => $repository->purge($bin))->toThrow(FixtureNotFoundException::class);
    });
});
