<?php

use Playerarm123\LaravelWorkflowKit\Console\Commands\Setup\UiComponents;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;

/**
 * kit:ui-add's decisions about shadcn components, apart from running the CLI
 * (KitUiAddCommandTest runs it).
 */
const UI_COMPONENTS_JSON = [
    'tailwind' => ['css' => 'resources/css/app.css'],
    'aliases' => [
        'components' => '@/components',
        'utils' => '@/lib/utils',
        'ui' => '@/components/ui',
        'lib' => '@/lib',
        'hooks' => '@/hooks',
    ],
];

const UI_TSCONFIG = ['compilerOptions' => ['paths' => ['@/*' => ['./resources/js/*']]]];

describe('settle', function () {
    it('drops a forbidden package, cn first, and installs one the stack lists on its line', function () {
        $settled = UiComponents::settle(
            [['name' => 'command', 'dependencies' => ['cn', 'cmdk', '@radix-ui/react-dialog']]],
            ['dependencies' => ['react' => '^19.0.0']],
            WorkflowKit::stack()['npm'],
            [],
        );

        expect($settled['install'])->toBe(['cmdk@^1'])
            ->and($settled['installDev'])->toBe([])
            ->and(array_keys($settled['dropped']))->toBe(['cn', '@radix-ui/react-dialog'])
            ->and($settled['dropped']['cn'])->toContain('@/lib/utils')
            ->and($settled['dropped']['@radix-ui/react-dialog'])->toContain('@radix-ui/*')
            ->and($settled['awaiting'])->toBe([]);
    });

    it('leaves a package the project already declares alone, listed in stack.md or not', function () {
        $settled = UiComponents::settle(
            [['name' => 'button', 'dependencies' => ['class-variance-authority', 'radix-ui']]],
            ['dependencies' => ['class-variance-authority' => '^0.7.1', 'radix-ui' => '^1.4.3']],
            WorkflowKit::stack()['npm'],
            [],
        );

        expect($settled['install'])->toBe([])->and($settled['awaiting'])->toBe([]);
    });

    it('holds a package stack.md does not list until --allow names it', function () {
        $items = [['name' => 'drawer', 'dependencies' => ['vaul@^1.1.2']], ['name' => 'other', 'devDependencies' => ['left-pad']]];
        $npm = WorkflowKit::stack()['npm'];

        $held = UiComponents::settle($items, [], $npm, []);
        $allowed = UiComponents::settle($items, [], $npm, ['vaul', 'left-pad']);

        expect($held['awaiting'])->toBe(['vaul', 'left-pad'])
            ->and($held['install'])->toBe([])
            ->and($allowed['awaiting'])->toBe([])
            ->and($allowed['install'])->toBe(['vaul@^1.1.2'])
            ->and($allowed['installDev'])->toBe(['left-pad']);
    });

    it('lists each package once across the items', function () {
        $settled = UiComponents::settle(
            [['name' => 'command', 'dependencies' => ['cmdk', 'cn']], ['name' => 'dialog', 'dependencies' => ['cn']], ['name' => 'combo', 'dependencies' => ['cmdk@^1.1.1']]],
            [],
            WorkflowKit::stack()['npm'],
            [],
        );

        expect($settled['install'])->toBe(['cmdk@^1.1.1'])->and(array_keys($settled['dropped']))->toBe(['cn']);
    });
});

describe('fix', function () {
    it('imports cn from the utils alias, in either quote', function (string $import) {
        expect(UiComponents::fix($import."\n\nexport const a = cn('x');\n", '@/lib/utils'))
            ->toBe("import { cn } from '@/lib/utils';\n\nexport const a = cn('x');\n");
    })->with([
        'double' => 'import { cn } from "cn";',
        'single' => "import { cn } from 'cn';",
    ]);

    it('follows the alias components.json names', function () {
        expect(UiComponents::fix('import { cn } from "cn"', '~/utils/cn'))->toBe("import { cn } from '~/utils/cn'");
    });

    it('leaves other packages whose name starts with cn alone', function () {
        $code = "import { x } from 'cnx';\nimport { y } from 'cn/extra';\n";

        expect(UiComponents::fix($code, '@/lib/utils'))->toBe($code);
    });

    it('moves @radix-ui imports to radix-ui, as kit:setup does', function () {
        expect(UiComponents::fix('import * as DialogPrimitive from "@radix-ui/react-dialog"', '@/lib/utils'))
            ->toBe('import { Dialog as DialogPrimitive } from "radix-ui"');
    });

    it('leaves the flash toast out of sonner\'s Toaster', function () {
        $code = "import { useFlashToast } from '@/hooks/use-flash-toast';\nfunction Toaster() {\n    const a = 1;\n\n    useFlashToast();\n\n    return a;\n}\n";

        expect(UiComponents::fix($code, '@/lib/utils'))->toBe("function Toaster() {\n    const a = 1;\n\n    return a;\n}\n");
    });
});

describe('aliases', function () {
    it('finds the folders the file aliases point at through tsconfig paths', function () {
        expect(UiComponents::aliasRoots(UI_COMPONENTS_JSON, UI_TSCONFIG))
            ->toBe(['resources/js/components', 'resources/js/components/ui', 'resources/js/hooks', 'resources/js/lib']);
    });

    it('falls back to resources/js for @/ when tsconfig says nothing', function () {
        expect(UiComponents::aliasRoots(UI_COMPONENTS_JSON, []))->toContain('resources/js/components/ui');
    });

    it('reads a tsconfig.json with comments and trailing commas', function () {
        $json = "{\n  // paths\n  \"compilerOptions\": {\n    /* alias */\n    \"paths\": { \"@/*\": [\"./resources/js/*\"], },\n  },\n}\n";

        expect(UiComponents::decodeJsonc($json))->toBe(UI_TSCONFIG);
    });

    it('places a registry file under the alias of its type', function () {
        expect(UiComponents::targetOf(['path' => 'registry/new-york-v4/ui/command.tsx', 'type' => 'registry:ui'], UI_COMPONENTS_JSON, UI_TSCONFIG))->toBe('resources/js/components/ui/command.tsx')
            ->and(UiComponents::targetOf(['path' => 'registry/new-york-v4/hooks/use-mobile.ts', 'type' => 'registry:hook'], UI_COMPONENTS_JSON, UI_TSCONFIG))->toBe('resources/js/hooks/use-mobile.ts');
    });

    it('reads the utils alias, @/lib/utils when components.json names none', function () {
        expect(UiComponents::utilsAlias(UI_COMPONENTS_JSON))->toBe('@/lib/utils')
            ->and(UiComponents::utilsAlias([]))->toBe('@/lib/utils');
    });

    it('matches a file named with --overwrite by its path or its trailing path', function () {
        $path = 'resources/js/components/ui/dialog.tsx';

        expect(UiComponents::isNamed($path, ['dialog.tsx']))->toBeTrue()
            ->and(UiComponents::isNamed($path, ['ui/dialog.tsx']))->toBeTrue()
            ->and(UiComponents::isNamed($path, ['./resources/js/components/ui/dialog.tsx']))->toBeTrue()
            ->and(UiComponents::isNamed($path, ['alert-dialog.tsx']))->toBeFalse()
            ->and(UiComponents::isNamed('resources/js/components/dialog.tsx', ['ui/dialog.tsx']))->toBeFalse();
    });
});
