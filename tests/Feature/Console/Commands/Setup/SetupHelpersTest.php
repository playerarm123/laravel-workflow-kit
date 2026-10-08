<?php

use Playerarm123\LaravelWorkflowKit\Console\Commands\Setup\EnvFile;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Setup\PhpFile;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Setup\RadixImports;

describe('RadixImports', function () {
    it('moves a namespace import to the radix-ui package', function () {
        expect(RadixImports::rewrite('import * as DropdownMenuPrimitive from "@radix-ui/react-dropdown-menu"'))
            ->toBe('import { DropdownMenu as DropdownMenuPrimitive } from "radix-ui"');
    });

    it('moves Slot and uses its Root', function () {
        $code = "import { Slot } from \"@radix-ui/react-slot\"\n\nconst Comp = asChild ? Slot : \"button\"\n";

        expect(RadixImports::rewrite($code))->toBe("import { Slot } from \"radix-ui\"\n\nconst Comp = asChild ? Slot.Root : \"button\"\n");
    });

    it('leaves a file without radix imports alone', function () {
        expect(RadixImports::rewrite("import { Slot } from 'radix-ui';\nconst A = Slot.Root;\n"))->toBe("import { Slot } from 'radix-ui';\nconst A = Slot.Root;\n");
    });
});

describe('EnvFile', function () {
    it('sets a key, uncommenting it in place', function () {
        expect(EnvFile::set("A=1\n# DB_HOST=127.0.0.1\nB=2\n", 'DB_HOST', 'db'))->toBe("A=1\nDB_HOST=db\nB=2\n");
    });

    it('adds a missing key at the end', function () {
        expect(EnvFile::set("A=1\n", 'NIGHTWATCH_TOKEN', ''))->toBe("A=1\n\nNIGHTWATCH_TOKEN=\n");
    });

    it('keeps a value that is already set when asked to set it only when missing', function () {
        expect(EnvFile::set("NIGHTWATCH_TOKEN=secret\n", 'NIGHTWATCH_TOKEN', '', onlyWhenMissing: true))->toBe("NIGHTWATCH_TOKEN=secret\n");
    });
});

describe('PhpFile', function () {
    it('adds a use statement in alphabetical order', function () {
        $code = "<?php\n\nuse App\\B;\nuse Illuminate\\C;\n\nreturn [];\n";

        expect(PhpFile::addUse($code, 'App\\Http\\X'))->toBe("<?php\n\nuse App\\B;\nuse App\\Http\\X;\nuse Illuminate\\C;\n\nreturn [];\n");
    });

    it('adds a use statement after the last one', function () {
        expect(PhpFile::addUse("<?php\n\nuse App\\A;\n\nx();\n", 'Zed\\Z'))->toBe("<?php\n\nuse App\\A;\nuse Zed\\Z;\n\nx();\n");
    });

    it('does not add a use statement twice', function () {
        expect(PhpFile::addUse("<?php\n\nuse App\\A;\n", 'App\\A'))->toBe("<?php\n\nuse App\\A;\n");
    });
});
