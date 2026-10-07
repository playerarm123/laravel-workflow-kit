<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Playerarm123\LaravelWorkflowKit\Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Rewrites a real file the generator tests share (the `@/types` barrel) under the same lock the
 * generators take, so a --parallel run never reads it half written and writes it back empty.
 *
 * @param  Closure(string): string  $change
 */
function rewriteSharedFile(string $path, Closure $change): void
{
    $handle = fopen($path, 'c+');

    if ($handle === false) {
        return;
    }

    try {
        flock($handle, LOCK_EX);
        $contents = (string) stream_get_contents($handle);
        $changed = $change($contents);

        if ($changed !== $contents) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $changed);
            fflush($handle);
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Seeds a scratch aggregate for a generator test.
 *
 * The domain picker offers only folders that already hold a root entity, so the scratch folder
 * must look like a real one. make:entity's and make:eloquent-repository's tests share it, so it
 * lives here rather than being declared twice, which would be a fatal redeclaration.
 *
 * Each test file picks a context name no other file uses: --parallel runs the files in separate
 * processes over the same app/ folder, and two files sharing a name would delete each other's.
 */
function seedSamplingAggregate(string $context): void
{
    File::ensureDirectoryExists(app_path("Domain/{$context}/Sample"));
    File::put(app_path("Domain/{$context}/Sample/SampleEntity.php"), '<?php // placeholder');
}

/**
 * The choices a generator's picker offers right now.
 *
 * Read straight from ResolvesDomain instead of asserting the whole list through expectsChoice,
 * because that list comes from the real folders under app/, which other generator tests create
 * and delete in other processes. Asserting only "is or is not in the list" keeps it stable.
 *
 * @return list<string>
 */
function domainChoicesOf(string $command): array
{
    $instance = Artisan::all()[$command];

    return (fn () => $this->availableDomains())->call($instance);
}
