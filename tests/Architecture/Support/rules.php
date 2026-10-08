<?php

use Composer\InstalledVersions;

/**
 * Shared helpers for the rule tests under the workflow kit's tests/Architecture, which a
 * project runs as its `Architecture` testsuite.
 *
 * These tests run on the bare PHPUnit TestCase (the project's tests/Pest.php only boots
 * Laravel for Feature and Browser), so everything here reads the project's files directly
 * and never touches the container. Every name starts with `rule` because Pest helpers share
 * one global namespace across the whole suite.
 *
 * Portable on purpose: nothing here may name a class, path or package that belongs to
 * one project — the same checks run on every Laravel app that installs the kit.
 */
const RULE_OVERRIDE_FIELDS = ['rule', 'check', 'subject', 'reason', 'approved_by', 'date'];

const RULE_OVERRIDE_MIN_REASON_LENGTH = 20;

/**
 * A path in the project the checks run on. They ship in the workflow kit package, so the root
 * is the one Composer installed it into, never a path counted up from this file: vendor/ may
 * hold the package as a copy or as a symlink, and __DIR__ resolves the symlink.
 *
 * WORKFLOW_KIT_PROJECT_PATH points the checks at another app, relative to that root. Only the
 * kit's own test suite sets it, to read its workbench app; a project never needs it.
 */
function ruleProjectPath(string $relative = ''): string
{
    static $root = null;

    if ($root === null) {
        $root = rtrim((string) realpath(InstalledVersions::getRootPackage()['install_path']), '/');
        $app = getenv('WORKFLOW_KIT_PROJECT_PATH');

        if (is_string($app) && $app !== '') {
            $root = rtrim((string) realpath($root.'/'.$app), '/');
        }
    }

    return $relative === '' ? $root : $root.'/'.ltrim($relative, '/');
}

/**
 * @return array<string, mixed>
 */
function ruleReadJson(string $relative): array
{
    $path = ruleProjectPath($relative);

    if (! is_file($path)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($path), true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Every problem with the override entries, one message per broken entry and field.
 *
 * @param  array<string, mixed>  $document
 * @return list<string>
 */
function ruleOverrideProblems(array $document): array
{
    $entries = $document['overrides'] ?? null;

    if (! is_array($entries) || ! array_is_list($entries)) {
        return ['rule-overrides.json must hold an "overrides" list'];
    }

    $problems = [];

    foreach ($entries as $index => $entry) {
        if (! is_array($entry)) {
            $problems[] = sprintf('overrides[%d] must be an object', $index);

            continue;
        }

        foreach (RULE_OVERRIDE_FIELDS as $field) {
            if (! is_string($entry[$field] ?? null) || trim($entry[$field]) === '') {
                $problems[] = sprintf('overrides[%d] is missing "%s"', $index, $field);
            }
        }

        $reason = is_string($entry['reason'] ?? null) ? trim($entry['reason']) : '';

        if ($reason !== '' && mb_strlen($reason) < RULE_OVERRIDE_MIN_REASON_LENGTH) {
            $problems[] = sprintf(
                'overrides[%d] "reason" must be at least %d characters — say why the rule cannot hold here',
                $index,
                RULE_OVERRIDE_MIN_REASON_LENGTH,
            );
        }

        $date = is_string($entry['date'] ?? null) ? $entry['date'] : '';

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($date !== '' && ($parsed === false || $parsed->format('Y-m-d') !== $date)) {
            $problems[] = sprintf('overrides[%d] "date" must be Y-m-d, got "%s"', $index, $date);
        }
    }

    return $problems;
}

/**
 * Whether a well-formed entry exempts this exact rule/check/subject. A broken entry
 * exempts nothing — RuleOverridesTest reports it instead.
 *
 * @param  array<string, mixed>  $document
 */
function ruleIsOverridden(array $document, string $rule, string $check, string $subject): bool
{
    foreach ($document['overrides'] ?? [] as $entry) {
        if (! is_array($entry) || ruleOverrideProblems(['overrides' => [$entry]]) !== []) {
            continue;
        }

        if ($entry['rule'] === $rule && $entry['check'] === $check && $entry['subject'] === $subject) {
            return true;
        }
    }

    return false;
}

/**
 * The violations that no override excuses, formatted so a red run names the rule,
 * the check, the subject and where to read about it.
 *
 * @param  list<array{subject: string, message: string}>  $violations
 * @return list<string>
 */
function ruleUnexcused(string $rule, string $check, array $violations): array
{
    $overrides = ruleReadJson('rule-overrides.json');

    return array_values(array_map(
        fn (array $violation): string => sprintf(
            '[%s:%s] %s: %s — see %s.md in the workflow kit\'s guidelines',
            $rule,
            $check,
            $violation['subject'],
            $violation['message'],
            $rule,
        ),
        array_filter(
            $violations,
            fn (array $violation): bool => ! ruleIsOverridden($overrides, $rule, $check, $violation['subject']),
        ),
    ));
}

/**
 * The kit's own copy of a project file, which `php artisan kit:install` writes: under
 * resources/kit/files when the project must keep it as the kit ships it, under
 * resources/kit/scaffold when the kit writes it once and the project owns it from then on.
 * A pattern (`database/migrations/*_create_x_table.php`) resolves to the file it matches.
 *
 * @return array{path: string, scaffold: bool}|null
 */
function ruleKitCopyOf(string $relative): ?array
{
    $kit = dirname(__DIR__, 3).'/resources/kit';

    foreach (['files' => false, 'scaffold' => true] as $folder => $scaffold) {
        $matches = glob($kit.'/'.$folder.'/'.$relative) ?: [];

        if ($matches !== []) {
            return ['path' => $matches[0], 'scaffold' => $scaffold];
        }
    }

    return null;
}

/**
 * Every kit file that is missing from the project or no longer reads as the kit's copy. A file
 * the kit writes once (resources/kit/scaffold) only has to exist. A path under vendor/ ships
 * in the package itself, so it only has to be installed.
 *
 * @param  list<string>  $paths
 * @return list<array{subject: string, message: string}>
 */
function ruleKitFileViolations(array $paths): array
{
    $violations = [];

    foreach ($paths as $relative) {
        $installed = ruleGlob(ruleProjectPath($relative));

        if ($installed === []) {
            $violations[] = ['subject' => $relative, 'message' => str_starts_with($relative, 'vendor/')
                ? 'is missing — install playerarm123/laravel-workflow-kit'
                : 'is missing — run `php artisan kit:install`'];

            continue;
        }

        if (str_starts_with($relative, 'vendor/')) {
            continue;
        }

        $copy = ruleKitCopyOf($relative);

        if ($copy === null) {
            $violations[] = ['subject' => $relative, 'message' => 'has no copy in the kit\'s resources/kit — the kit must ship it'];
        } elseif (! $copy['scaffold'] && file_get_contents($installed[0]) !== file_get_contents($copy['path'])) {
            $violations[] = ['subject' => $relative, 'message' => 'differs from the kit\'s copy — run `php artisan kit:install --force`, or move the change into the kit'];
        }
    }

    return $violations;
}

/**
 * Does an installed version sit on the locked line? `13` matches any 13.x; a 0.x line
 * is locked at the minor (`0.1`) because 0.x minors break like majors do.
 */
function ruleVersionMatches(string $installed, string $locked): bool
{
    $installedParts = explode('.', ltrim($installed, 'vV'));
    $lockedParts = explode('.', $locked);

    return array_slice($installedParts, 0, count($lockedParts)) === $lockedParts;
}

/**
 * @return array<string, string> package => installed version, from composer.lock
 */
function ruleComposerInstalled(): array
{
    $lock = ruleReadJson('composer.lock');
    $installed = [];

    foreach ([...($lock['packages'] ?? []), ...($lock['packages-dev'] ?? [])] as $package) {
        $installed[$package['name']] = $package['version'];
    }

    return $installed;
}

/**
 * The JavaScript lockfile the project keeps: npm's, pnpm's or bun's, whichever is there.
 */
function ruleNpmLockfile(): string
{
    foreach (['package-lock.json', 'pnpm-lock.yaml', 'bun.lock'] as $lockfile) {
        if (is_file(ruleProjectPath($lockfile))) {
            return $lockfile;
        }
    }

    return 'package-lock.json';
}

/**
 * @return array<string, string> package => installed version, from the lockfile ruleNpmLockfile() names
 */
function ruleNpmInstalled(): array
{
    return match (ruleNpmLockfile()) {
        'pnpm-lock.yaml' => rulePnpmInstalled((string) file_get_contents(ruleProjectPath('pnpm-lock.yaml'))),
        'bun.lock' => ruleBunInstalled((string) file_get_contents(ruleProjectPath('bun.lock'))),
        default => ruleNpmLockInstalled(ruleReadJson('package-lock.json')),
    };
}

/**
 * @param  array<string, mixed>  $lock
 * @return array<string, string>
 */
function ruleNpmLockInstalled(array $lock): array
{
    $installed = [];

    foreach ($lock['packages'] ?? [] as $path => $package) {
        if (! str_starts_with($path, 'node_modules/') || str_contains(substr($path, 13), 'node_modules/')) {
            continue;
        }

        $installed[substr($path, 13)] = $package['version'] ?? '';
    }

    return $installed;
}

/**
 * The root importer of pnpm-lock.yaml (v6 and later), read without a YAML parser: each direct
 * dependency is a name at six spaces with its `version:` at eight, the peer suffix cut off.
 *
 * @return array<string, string>
 */
function rulePnpmInstalled(string $lock): array
{
    if (preg_match('/^importers:\n\n?  \.:\n((?:(?:    .*)?\n)*)/m', $lock, $root) !== 1) {
        return [];
    }

    preg_match_all("/^      '?([^'\s:]+)'?:\n        specifier: .*\n        version: ([^\s(]+)/m", $root[1], $matches, PREG_SET_ORDER);

    $installed = [];

    foreach ($matches as [, $package, $version]) {
        $installed[$package] = $version;
    }

    return $installed;
}

/**
 * bun.lock is JSON with trailing commas. Each top-level package reads `"name": ["name@version", …]`;
 * a nested one is keyed under its parent (`parent/name`).
 *
 * @return array<string, string>
 */
function ruleBunInstalled(string $lock): array
{
    $decoded = json_decode((string) preg_replace('/,(\s*[}\]])/', '$1', $lock), true);
    $installed = [];

    foreach ((is_array($decoded) ? $decoded['packages'] ?? [] : []) as $package => $entry) {
        $package = (string) $package;

        if (substr_count($package, '/') > (str_starts_with($package, '@') ? 1 : 0) || ! is_array($entry) || ! is_string($entry[0] ?? null)) {
            continue;
        }

        $installed[$package] = substr($entry[0], (int) strrpos($entry[0], '@') + 1);
    }

    return $installed;
}

/**
 * @return array<string, string> package => constraint, production "require" only
 */
function ruleComposerReadRequire(): array
{
    return ruleReadJson('composer.json')['require'] ?? [];
}

/**
 * @return array<string, string> package => constraint, require and require-dev together
 */
function ruleComposerDeclared(): array
{
    $composer = ruleReadJson('composer.json');

    return [...($composer['require'] ?? []), ...($composer['require-dev'] ?? [])];
}

/**
 * @return array<string, string> package => constraint, every dependency group together
 */
function ruleNpmDeclared(): array
{
    $package = ruleReadJson('package.json');

    return [
        ...($package['dependencies'] ?? []),
        ...($package['devDependencies'] ?? []),
        ...($package['optionalDependencies'] ?? []),
    ];
}

/**
 * Declared packages matching a forbidden name; a trailing `*` matches a whole vendor
 * or prefix (`@radix-ui/*`, `ag-grid-*`).
 *
 * @param  array<string, string>  $declared
 * @param  list<string>  $forbidden
 * @return list<array{subject: string, message: string}>
 */
function ruleForbiddenFound(array $declared, array $forbidden, string $because): array
{
    $violations = [];

    foreach (array_keys($declared) as $package) {
        foreach ($forbidden as $pattern) {
            if (fnmatch($pattern, $package)) {
                $violations[] = ['subject' => $package, 'message' => sprintf('is forbidden (%s)', $because)];
            }
        }
    }

    return $violations;
}

/**
 * Value of a key in a dotenv file, or null when the key is absent.
 */
function ruleEnvValue(string $relative, string $key): ?string
{
    $path = ruleProjectPath($relative);

    if (! is_file($path)) {
        return null;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*'.preg_quote($key, '/').'\s*=(.*)$/', $line, $matches) === 1) {
            return trim(trim($matches[1]), '"\'');
        }
    }

    return null;
}

/**
 * Value of an <env name="…" value="…"/> in phpunit.xml, or null when it is not set there.
 */
function rulePhpunitEnv(string $key): ?string
{
    $path = ruleProjectPath('phpunit.xml');

    if (! is_file($path)) {
        return null;
    }

    $xml = simplexml_load_file($path);

    foreach ($xml === false ? [] : $xml->xpath('//php/env') as $env) {
        if ((string) $env['name'] === $key) {
            return (string) $env['value'];
        }
    }

    return null;
}

/**
 * Whether a path belongs to a generator test's scratch files, which every check skips.
 *
 * The tests of the make:* generators write their fixtures and output into the real app/,
 * resources/js/ and tests/ folders, then delete them. A check running at the same moment in
 * another --parallel process would read them as the project's own code. So the name
 * `Sampling` anywhere in a file or folder name, and `sampling-` at the start of one, is
 * reserved for those files: a scratch file must carry it, and real code never does
 * (testing.md).
 */
function ruleIsScratch(string $relative): bool
{
    return preg_match('#(^|/)[^/]*Sampling|(^|/)sampling-#', $relative) === 1;
}

/**
 * `glob()` without the generator tests' scratch paths. Every check lists files through this
 * or ruleSourceFiles(), never through `glob()` itself.
 *
 * @return list<string> the matching paths, as `glob()` returns them
 */
function ruleGlob(string $pattern, int $flags = 0): array
{
    return array_values(array_filter(
        glob($pattern, $flags) ?: [],
        fn (string $path): bool => ! ruleIsScratch(ltrim(substr($path, strlen(ruleProjectPath())), '/')),
    ));
}

/**
 * Source files under a directory, skipping the paths given relative to the project root and
 * the generator tests' scratch files (ruleIsScratch()).
 *
 * @param  list<string>  $extensions
 * @param  list<string>  $skip
 * @return list<string> paths relative to the project root
 */
function ruleSourceFiles(string $directory, array $extensions, array $skip = []): array
{
    $root = ruleProjectPath($directory);

    if (! is_dir($root)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $relative = ltrim(substr($file->getPathname(), strlen(ruleProjectPath())), '/');

        if (! $file->isFile() || ! in_array($file->getExtension(), $extensions, true) || ruleIsScratch($relative)) {
            continue;
        }

        foreach ($skip as $skipped) {
            if (str_starts_with($relative, rtrim($skipped, '/').'/')) {
                continue 2;
            }
        }

        $files[] = $relative;
    }

    sort($files);

    return $files;
}

/**
 * The code of a source file with its comments removed, so a rule that bans a call or a
 * literal does not trip over a docblock explaining why that call is banned.
 *
 * PHP is tokenised exactly. TS/JS uses a regex that drops block and line comments; a `//`
 * inside a string can hide the rest of that line, which can only miss a violation, never
 * invent one.
 */
function ruleCodeWithoutComments(string $relative): string
{
    $source = (string) file_get_contents(ruleProjectPath($relative));

    if (str_ends_with($relative, '.php')) {
        return implode('', array_map(
            fn (array|string $token): string => match (true) {
                is_string($token) => $token,
                in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) => '',
                default => $token[1],
            },
            token_get_all($source),
        ));
    }

    return (string) preg_replace(['#/\*.*?\*/#s', '#(?<![:\'"])//[^\n]*#'], '', $source);
}

/**
 * Every line of code (comments removed) in the given files that matches a pattern.
 *
 * @param  list<string>  $files
 * @return list<array{subject: string, message: string}>
 */
function ruleCodeMatches(array $files, string $pattern, string $message): array
{
    $violations = [];

    foreach ($files as $file) {
        foreach (explode("\n", ruleCodeWithoutComments($file)) as $line) {
            if (preg_match($pattern, $line) === 1) {
                $violations[] = ['subject' => $file, 'message' => sprintf('%s: `%s`', $message, trim($line))];
            }
        }
    }

    return $violations;
}

/**
 * The namespace a PHP file declares, or '' for the global namespace.
 */
function ruleNamespaceOf(string $relative): string
{
    $source = (string) file_get_contents(ruleProjectPath($relative));

    return preg_match('/^namespace\s+([^;\s]+)\s*;/m', $source, $matches) === 1 ? $matches[1] : '';
}

/**
 * The class a PHP file declares, from its namespace and file name.
 */
function ruleClassOf(string $relative): string
{
    return ruleNamespaceOf($relative).'\\'.basename($relative, '.php');
}

/**
 * Every concrete class declared under a directory (no interface, trait, enum or abstract
 * class), outside the namespaces given.
 *
 * @param  list<string>  $skipNamespaces
 * @return list<class-string>
 */
function ruleConcreteClassesIn(string $directory, array $skipNamespaces = []): array
{
    $classes = [];

    foreach (ruleSourceFiles($directory, ['php']) as $file) {
        $class = ruleClassOf($file);

        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || $reflection->isEnum()) {
            continue;
        }

        foreach ($skipNamespaces as $namespace) {
            if (ruleIsIn($class, $namespace)) {
                continue 2;
            }
        }

        $classes[] = $class;
    }

    return $classes;
}

/**
 * The actions a controller declares itself: its public instance methods, `__invoke` included.
 *
 * @param  class-string  $controller
 * @return list<ReflectionMethod>
 */
function ruleControllerActions(string $controller): array
{
    return array_values(array_filter(
        (new ReflectionClass($controller))->getMethods(ReflectionMethod::IS_PUBLIC),
        fn (ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $controller
            && ! $method->isStatic()
            && ($method->getName() === '__invoke' || ! str_starts_with($method->getName(), '__')),
    ));
}

/**
 * Every class-like name a PHP file depends on: its imports (grouped ones expanded)
 * and every fully qualified name written in its code. Comments never count.
 *
 * @return list<string> names without a leading backslash, sorted and unique
 */
function ruleDependenciesOf(string $relative): array
{
    $tokens = token_get_all((string) file_get_contents(ruleProjectPath($relative)));
    $names = [];
    $depth = 0;
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token === '{') {
            $depth++;
        } elseif ($token === '}') {
            $depth--;
        }

        if (! is_array($token)) {
            continue;
        }

        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            $names[] = ltrim($token[1], '\\');

            continue;
        }

        if ($token[0] !== T_USE || $depth > 0) {
            continue;
        }

        $statement = '';

        for ($i++; $i < $count && $tokens[$i] !== ';'; $i++) {
            $statement .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }

        $statement = (string) preg_replace('/^\s*(function|const)\s+/', '', $statement);

        if (preg_match('/^\s*([^{]+?)\\\\?\s*\{(.*)\}\s*$/s', $statement, $group) === 1) {
            foreach (explode(',', $group[2]) as $member) {
                $member = trim((string) preg_replace('/\s+as\s+\w+$/i', '', trim($member)));

                if ($member !== '') {
                    $names[] = rtrim(trim($group[1]), '\\').'\\'.$member;
                }
            }

            continue;
        }

        foreach (explode(',', $statement) as $import) {
            $import = trim((string) preg_replace('/\s+as\s+\w+$/i', '', trim($import)));

            if ($import !== '') {
                $names[] = ltrim($import, '\\');
            }
        }
    }

    $names = array_values(array_unique($names));
    sort($names);

    return $names;
}

/**
 * Whether a class name sits in a namespace or below it.
 */
function ruleIsIn(string $name, string $namespace): bool
{
    return $name === $namespace || str_starts_with($name, $namespace.'\\');
}

/**
 * The top-level string keys of the array a method returns (`'key' => …`), in order — the
 * wire contract of an `Arrayable::toArray()`. Nested arrays add no keys. A spread comes back
 * as its expression prefixed with `...` (`...$this->createdAt->toFilters()`), for the caller
 * to resolve or refuse.
 *
 * @return list<string>|null null when the file declares no such method or it returns no array literal
 */
function ruleArrayKeysOf(string $relative, string $method): ?array
{
    $tokens = array_values(array_filter(
        token_get_all((string) file_get_contents(ruleProjectPath($relative))),
        fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $count = count($tokens);

    for ($i = 0; $i < $count - 1; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION || ! is_array($tokens[$i + 1]) || $tokens[$i + 1][1] !== $method) {
            continue;
        }

        for ($j = $i + 2; $j < $count; $j++) {
            if (is_array($tokens[$j]) && $tokens[$j][0] === T_RETURN) {
                break;
            }
        }

        if ($j + 1 >= $count || $tokens[$j + 1] !== '[') {
            return null;
        }

        $keys = [];
        $depth = 0;

        for ($k = $j + 1; $k < $count; $k++) {
            $token = $tokens[$k];

            if (in_array($token, ['[', '(', '{'], true) || (is_array($token) && $token[0] === T_CURLY_OPEN)) {
                $depth++;
            } elseif (in_array($token, [']', ')', '}'], true)) {
                if (--$depth === 0) {
                    return $keys;
                }
            } elseif ($depth === 1 && is_array($token) && $token[0] === T_ELLIPSIS) {
                $spread = '...';
                $inner = 0;

                for ($k++; $k < $count; $k++) {
                    $part = $tokens[$k];

                    if (in_array($part, ['[', '(', '{'], true)) {
                        $inner++;
                    } elseif (in_array($part, [']', ')', '}'], true) && --$inner < 0) {
                        break;
                    } elseif ($part === ',' && $inner === 0) {
                        break;
                    }

                    $spread .= is_array($part) ? $part[1] : $part;
                }

                $keys[] = $spread;
                $k--;
            } elseif ($depth === 1
                && is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING
                && is_array($tokens[$k + 1] ?? null) && $tokens[$k + 1][0] === T_DOUBLE_ARROW) {
                $keys[] = substr($token[1], 1, -1);
            }
        }

        return null;
    }

    return null;
}

/**
 * The top-level keys of an exported TypeScript object type (`export type Name = { … }`), in
 * order. Members of nested object types are not keys of the outer type.
 *
 * @return list<string>|null null when the file exports no object-literal type of that name
 */
function ruleTsTypeKeys(string $relative, string $name): ?array
{
    $code = ruleCodeWithoutComments($relative);

    if (preg_match('/\bexport\s+type\s+'.preg_quote($name, '/').'\s*=\s*\{/', $code, $match, PREG_OFFSET_CAPTURE) !== 1) {
        return null;
    }

    $body = '';
    $depth = 1;
    $length = strlen($code);

    for ($i = $match[0][1] + strlen($match[0][0]); $i < $length; $i++) {
        $char = $code[$i];

        if (in_array($char, ['{', '(', '['], true)) {
            $depth++;
        } elseif (in_array($char, ['}', ')', ']'], true) && --$depth === 0) {
            break;
        }

        $body .= $char;
    }

    return ruleTsLiteralKeys($body);
}

/**
 * The keys of an exported TypeScript type found in any of the given files, following the shapes
 * a wire contract is written in: an object literal, another exported type by name, or an
 * intersection of those (`DtFilters & { role: string | null }`).
 *
 * @param  list<string>  $files
 * @return list<string>|null null when the type is missing or written in any other shape
 */
function ruleTsResolvedTypeKeys(array $files, string $name, int $depth = 0): ?array
{
    if ($depth > 8) {
        return null;
    }

    foreach ($files as $file) {
        $code = ruleCodeWithoutComments($file);

        if (preg_match('/\bexport\s+type\s+'.preg_quote($name, '/').'\s*=/', $code, $match, PREG_OFFSET_CAPTURE) !== 1) {
            continue;
        }

        $parts = [''];
        $nesting = 0;
        $length = strlen($code);

        for ($i = $match[0][1] + strlen($match[0][0]); $i < $length; $i++) {
            $char = $code[$i];

            if (in_array($char, ['{', '(', '[', '<'], true)) {
                $nesting++;
            } elseif (in_array($char, ['}', ')', ']', '>'], true) && ! ($char === '>' && ($code[$i - 1] ?? '') === '=')) {
                $nesting--;
            } elseif ($nesting === 0 && $char === ';') {
                break;
            } elseif ($nesting === 0 && $char === '&') {
                $parts[] = '';

                continue;
            }

            $parts[count($parts) - 1] .= $char;
        }

        $keys = [];

        foreach (array_map('trim', $parts) as $part) {
            if (str_starts_with($part, '{') && str_ends_with($part, '}')) {
                $keys = [...$keys, ...ruleTsLiteralKeys(substr($part, 1, -1))];
            } elseif (preg_match('/^[A-Za-z_$][\w$]*$/', $part) === 1) {
                $named = ruleTsResolvedTypeKeys($files, $part, $depth + 1);

                if ($named === null) {
                    return null;
                }

                $keys = [...$keys, ...$named];
            } else {
                return null;
            }
        }

        return array_values(array_unique($keys));
    }

    return null;
}

/**
 * The top-level keys inside the braces of a TypeScript object literal.
 *
 * @return list<string>
 */
function ruleTsLiteralKeys(string $body): array
{
    $outer = '';
    $nesting = 0;

    foreach (str_split($body) as $char) {
        if (in_array($char, ['{', '(', '['], true)) {
            $nesting++;
        } elseif (in_array($char, ['}', ')', ']'], true)) {
            $nesting--;
        } elseif ($nesting === 0) {
            $outer .= $char;
        }
    }

    preg_match_all('/(?:^|[;,\n])\s*(?:readonly\s+)?([A-Za-z_$][\w$]*|\'[^\']+\'|"[^"]+")\s*\??\s*:/', $outer, $keys);

    return array_map(fn (string $key): string => trim($key, '\'"'), $keys[1]);
}

/**
 * Every handler file under the UseCases folders a glob names (handlers.md).
 *
 * @return list<array{file: string, class: class-string, name: string, folder: ?string}> `folder` is the use case's own folder namespace, null for a plain handler
 */
function ruleHandlers(string $useCasesGlob): array
{
    $handlers = [];

    foreach (ruleGlob(ruleProjectPath($useCasesGlob), GLOB_ONLYDIR) as $directory) {
        $useCases = ltrim(substr($directory, strlen(ruleProjectPath())), '/');

        foreach (ruleSourceFiles($useCases, ['php']) as $file) {
            if (! str_ends_with($file, 'Handler.php')) {
                continue;
            }

            $relative = substr($file, strlen($useCases) + 1);
            $name = substr(basename($file), 0, -strlen('Handler.php'));
            $namespace = ruleNamespaceOf($file);

            $handlers[] = [
                'file' => $file,
                'class' => $namespace.'\\'.$name.'Handler',
                'name' => $name,
                'folder' => str_contains($relative, '/') ? $namespace : null,
            ];
        }
    }

    return $handlers;
}

/**
 * The class names a parameter or return type names, builtins included by their own name.
 *
 * @return list<string>
 */
function ruleTypeNames(?ReflectionType $type): array
{
    if ($type instanceof ReflectionNamedType) {
        return [$type->getName()];
    }

    if ($type instanceof ReflectionUnionType || $type instanceof ReflectionIntersectionType) {
        return array_merge([], ...array_map(ruleTypeNames(...), $type->getTypes()));
    }

    return [];
}

/**
 * The constructor-promoted properties of a class that can write: domain repositories, and
 * domain services that inject one themselves. A service that only computes (a planner) never
 * writes, so calling it beside one save needs no transaction.
 *
 * @param  class-string  $class
 * @return list<string> property names
 */
function ruleHandlerWriters(string $class): array
{
    $constructor = (new ReflectionClass($class))->getConstructor();
    $writers = [];

    foreach ($constructor?->getParameters() ?? [] as $parameter) {
        foreach (ruleTypeNames($parameter->getType()) as $type) {
            $repository = interface_exists($type) && ruleIsIn($type, 'App\\Domain') && str_ends_with($type, 'Repository');
            $service = class_exists($type) && ruleIsIn($type, 'App\\Domain') && str_contains($type, '\\Services\\') && ruleHandlerWriters($type) !== [];

            if ($repository || $service) {
                $writers[] = $parameter->getName();
            }
        }
    }

    return $writers;
}

/**
 * The code of a method's body with its comments removed, followed by the bodies of the methods of
 * its own class it calls through `$this->`, `self::` or `static::`, however deep. A check that asks
 * whether a method does something then lets it do so through a helper of its own class.
 *
 * @param  array<string, true>  $seen  the methods already read, so a recursion ends
 */
function ruleMethodBodyFollowing(ReflectionMethod $method, array &$seen = []): string
{
    $class = $method->getDeclaringClass();
    $file = $method->getFileName();
    $seen[$method->getName()] = true;

    if ($file === false || $method->getStartLine() === false || $method->getEndLine() === false) {
        return '';
    }

    $lines = array_slice((array) file($file), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
    $body = implode('', array_map(
        fn (array|string $token): string => match (true) {
            is_string($token) => $token,
            in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true) => '',
            default => $token[1],
        },
        token_get_all('<?php '.implode('', array_map(strval(...), $lines))),
    ));

    preg_match_all('/(?:\$this->|self::|static::)(\w+)\s*\(/', $body, $calls);

    foreach (array_unique($calls[1]) as $called) {
        if (isset($seen[$called]) || ! $class->hasMethod($called)) {
            continue;
        }

        $next = $class->getMethod($called);

        if ($next->getDeclaringClass()->getName() === $class->getName()) {
            $body .= "\n".ruleMethodBodyFollowing($next, $seen);
        }
    }

    return $body;
}
