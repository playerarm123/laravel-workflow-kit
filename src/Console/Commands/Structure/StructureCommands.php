<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Process;

use function Illuminate\Support\php_binary;

/**
 * The kit's commands the structure screen runs from its Run menu (structure.md): `kit:plan`,
 * `kit:apply`, `kit:import --sync` (and its dry run), `kit:retire`, and Wayfinder's route
 * generator when the project has it. The screen names a command by its key and, at most, the
 * context or HTTP resource it is open on; nothing else it sends reaches the command line.
 *
 * Each command runs as `php artisan …` in a process of its own, the way a developer types it: the
 * console registers commands a web request does not, and a command that writes classes leaves the
 * process it ran in holding them as they were. The screen then draws the graph again from a
 * request of its own.
 *
 * @phpstan-type Command array{command: string, options: list<string>, label: string, writes: bool, scopes: list<string>}
 */
final class StructureCommands
{
    public const string CONTEXT = 'context';

    public const string RESOURCE = 'resource';

    /**
     * Each command by key: what it runs, the options it always passes, what it is called on the
     * screen, whether it writes files, and which views it narrows to.
     *
     * @var array<string, Command>
     */
    public const array COMMANDS = [
        'plan' => ['command' => 'kit:plan', 'options' => [], 'label' => 'Plan', 'writes' => false, 'scopes' => [self::CONTEXT, self::RESOURCE]],
        'apply' => ['command' => 'kit:apply', 'options' => [], 'label' => 'Apply', 'writes' => true, 'scopes' => [self::CONTEXT, self::RESOURCE]],
        'sync-preview' => ['command' => 'kit:import', 'options' => ['--sync', '--dry-run'], 'label' => 'Preview sync from code', 'writes' => false, 'scopes' => [self::CONTEXT, self::RESOURCE]],
        'sync' => ['command' => 'kit:import', 'options' => ['--sync'], 'label' => 'Sync from code', 'writes' => true, 'scopes' => [self::CONTEXT, self::RESOURCE]],
        'retire' => ['command' => 'kit:retire', 'options' => [], 'label' => 'Retire replaced pieces', 'writes' => true, 'scopes' => [self::CONTEXT]],
        'routes' => ['command' => 'wayfinder:generate', 'options' => ['--with-form'], 'label' => 'Regenerate routes', 'writes' => true, 'scopes' => []],
    ];

    /**
     * The package each command needs besides the kit.
     */
    private const array PACKAGES = ['routes' => 'laravel/wayfinder'];

    /**
     * @param  list<string>|null  $artisan  how to start the console, `php artisan` in the project's root when null
     */
    public function __construct(
        private readonly StructureReader $reader,
        private readonly string $root,
        private readonly ?array $artisan = null,
    ) {}

    /**
     * The commands this project can run, for the screen's menu.
     *
     * @return list<array{key: string, label: string, writes: bool, scopes: list<string>}>
     */
    public function available(): array
    {
        $available = [];

        foreach (self::COMMANDS as $key => $command) {
            if ($this->installed($key)) {
                $available[] = ['key' => $key, 'label' => $command['label'], 'writes' => $command['writes'], 'scopes' => $command['scopes']];
            }
        }

        return $available;
    }

    /**
     * Why a command cannot run as asked, or null when it can.
     */
    public function refusal(string $key, ?string $context, ?string $resource): ?string
    {
        $command = self::COMMANDS[$key] ?? null;

        return match (true) {
            $command === null || ! $this->installed($key) => "There is no command {$key} to run here.",
            $context !== null && $resource !== null => 'A command runs on one context or one HTTP resource, not both.',
            $context !== null && ! in_array(self::CONTEXT, $command['scopes'], true) => "{$command['command']} does not narrow to one context.",
            $resource !== null && ! in_array(self::RESOURCE, $command['scopes'], true) => "{$command['command']} does not narrow to one HTTP resource.",
            $context !== null && ! in_array($context, $this->reader->contexts(), true) => "There is no context named {$context}.",
            $resource !== null && ! in_array($resource, $this->reader->resources(), true) => "There is no HTTP resource named {$resource}.",
            default => null,
        };
    }

    /**
     * The command line a run stands for, as a developer would type it.
     */
    public function describe(string $key, ?string $context, ?string $resource): string
    {
        return implode(' ', ['php artisan', ...$this->arguments($key, $context, $resource)]);
    }

    /**
     * Runs a command and hands back its line, its exit code and what it printed, without colour.
     * The caller has asked `refusal()` first.
     *
     * @return array{command: string, exitCode: int, output: string}
     */
    public function run(string $key, ?string $context, ?string $resource): array
    {
        $result = Process::path($this->root)
            ->forever()
            ->run([...($this->artisan ?? [php_binary(), 'artisan']), ...$this->arguments($key, $context, $resource), '--no-interaction', '--no-ansi']);

        return [
            'command' => $this->describe($key, $context, $resource),
            'exitCode' => (int) $result->exitCode(),
            'output' => (string) preg_replace('/\e\[[0-9;]*m/', '', $result->output().$result->errorOutput()),
        ];
    }

    /**
     * @return list<string>
     */
    private function arguments(string $key, ?string $context, ?string $resource): array
    {
        $command = self::COMMANDS[$key];

        return [
            $command['command'],
            ...$command['options'],
            ...($context === null ? [] : ["--context={$context}"]),
            ...($resource === null ? [] : ["--resource={$resource}"]),
        ];
    }

    private function installed(string $key): bool
    {
        $package = self::PACKAGES[$key] ?? null;

        return array_key_exists($key, self::COMMANDS) && ($package === null || InstalledVersions::isInstalled($package));
    }
}
