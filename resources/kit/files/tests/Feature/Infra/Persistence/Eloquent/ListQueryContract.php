<?php

use App\Application\Concerns\ListQueryException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cases every list read query must pass (list-queries.md), registered with
 * their fixed titles so the workflow kit's tests/Architecture/ListQueriesTest can find
 * them. An adapter's test file calls this once and keeps its own filter and sort cases
 * beside it.
 *
 * - criteria: the Criteria the adapter reads, built from overrides `perPage` (int) and
 *   `search` (string), with the defaults the handler would settle on otherwise
 * - seed: create $count rows inside the scope the criteria name, tying on the default sort
 *   (the contract freezes time first, so timestamps already tie)
 * - break: make the next read fail at the database (drop a column it selects or sorts by)
 * - seedMatching: one row inside the scope whose searchable column holds exactly $text
 * - seedOutOfScope: one row outside the scope whose searchable column holds exactly $text
 *
 * @param  class-string  $query  the Application port, resolved from the container
 * @param  Closure(array{perPage?: int, search?: string}): object  $criteria
 * @param  Closure(int): void  $seed
 * @param  Closure(): void  $break
 * @param  (Closure(string): void)|null  $seedMatching
 * @param  (Closure(string): void)|null  $seedOutOfScope
 */
function listQueryContract(
    string $query,
    Closure $criteria,
    Closure $seed,
    Closure $break,
    ?Closure $seedMatching = null,
    ?Closure $seedOutOfScope = null,
): void {
    $read = fn (array $overrides = []): LengthAwarePaginator => app($query)->paginate($criteria($overrides));

    describe('list query contract', function () use ($read, $seed, $break, $seedMatching, $seedOutOfScope) {
        it('pages the rows by the page size it was given', function () use ($read, $seed) {
            $seed(3);

            $page = $read(['perPage' => 2]);

            expect($page->total())->toBe(3)
                ->and($page->perPage())->toBe(2)
                ->and($page->items())->toHaveCount(2);
        });

        it('pages through rows that tie on the sort without losing or repeating one', function () use ($read, $seed) {
            $this->freezeTime();
            $seed(5);
            $ids = [];
            DB::enableQueryLog();

            foreach ([1, 2, 3] as $number) {
                Paginator::currentPageResolver(fn () => $number);

                foreach ($read(['perPage' => 2])->items() as $row) {
                    /** @var Arrayable<string, mixed> $row */
                    $ids[] = $row->toArray()['id'];
                }
            }

            /**
             * A small table comes back in insertion order even with no tie-breaker, so the
             * paging alone can pass by luck — the page query itself must end on the primary key.
             */
            $pageQueries = array_filter(
                array_column(DB::getQueryLog(), 'query'),
                fn (string $sql) => preg_match('/\blimit\b/i', $sql) === 1,
            );

            expect($ids)->toHaveCount(5)
                ->and(array_unique($ids))->toHaveCount(5)
                ->and($pageQueries)->not->toBeEmpty()
                ->each->toMatch('/order by .*[`"]?\w+[`"]?\.[`"]?id[`"]? (asc|desc) limit/i');
        });

        it('reads a page in the same number of queries whatever its size', function () use ($read, $seed) {
            $seed(1);
            DB::enableQueryLog();
            DB::flushQueryLog();
            $read();
            $oneRow = count(DB::getQueryLog());

            $seed(4);
            DB::flushQueryLog();
            $read();

            expect(count(DB::getQueryLog()))->toBe($oneRow);
        });

        it('wraps a refused query in a list query exception', function () use ($read, $seed, $break) {
            $seed(1);
            $break();

            expect(fn () => $read())->toThrow(ListQueryException::class);
        });

        if ($seedMatching !== null) {
            it('treats % and _ in the search as plain characters', function () use ($read, $seedMatching) {
                $seedMatching('kit 100% sure');
                $seedMatching('kit 1000 sure');
                $seedMatching('kit a_b');
                $seedMatching('kit acb');

                expect($read(['search' => '0%'])->total())->toBe(1)
                    ->and($read(['search' => 'a_b'])->total())->toBe(1);
            });
        }

        if ($seedOutOfScope !== null) {
            it('never lets a search reach past its scope', function () use ($read, $seedMatching, $seedOutOfScope) {
                $seedMatching?->__invoke('kit scope inside');
                $seedOutOfScope('kit scope outside');

                expect($read(['search' => 'kit scope'])->total())->toBe($seedMatching === null ? 0 : 1);
            });
        }
    });
}

/**
 * A `break` hook for any list: drop a column the read sorts by, so the database refuses it.
 * The test database runs each test in a transaction, which takes the DDL back.
 */
function breakListQueryColumn(string $table, string $column): void
{
    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
}
