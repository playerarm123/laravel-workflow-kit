<?php

/**
 * The kit's `resources/js/hooks/use-translation.ts`, run as the browser runs it: the workbench's
 * TypeScript transpiles it, and node calls `translate()` with React and Inertia stubbed out.
 *
 * @param  array<string, string|int>  $replace
 */
function kitTranslate(string $line, array $replace): string
{
    $workbench = dirname(__DIR__, 3).'/workbench';
    $source = dirname(__DIR__, 3).'/resources/kit/files/resources/js/hooks/use-translation.ts';

    if (! is_file($workbench.'/node_modules/typescript/package.json')) {
        test()->markTestSkipped('Run `npm ci` in workbench/ first.');
    }

    $script = <<<'JS'
        const ts = require('typescript');
        const [source, line, replace] = process.argv.slice(1);
        const { outputText } = ts.transpileModule(require('fs').readFileSync(source, 'utf8'), {
            compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
        });
        const module = { exports: {} };
        new Function('require', 'module', 'exports', outputText)(() => ({}), module, module.exports);
        process.stdout.write(module.exports.translate(line, JSON.parse(replace)));
        JS;

    $process = proc_open(
        ['node', '-e', $script, $source, $line, (string) json_encode((object) $replace)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workbench,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start node');
    }

    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0) {
        throw new RuntimeException($errors);
    }

    return $output;
}

describe('translate', function () {
    it('fills a token that is the start of another one without eating the longer one', function () {
        expect(kitTranslate('Showing :from–:to of :total', ['from' => 0, 'to' => 0, 'total' => 0]))->toBe('Showing 0–0 of 0')
            ->and(kitTranslate(':total items, :to shown', ['to' => 10, 'total' => 25]))->toBe('25 items, 10 shown');
    });

    it('leaves a token with no value as it is, and a line with no token untouched', function () {
        expect(kitTranslate('Hello :name, :missing', ['name' => 'Ann']))->toBe('Hello Ann, :missing')
            ->and(kitTranslate('Ratio 3:2', []))->toBe('Ratio 3:2');
    });
});
