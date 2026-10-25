<?php

use Illuminate\Support\Facades\File;

require_once dirname(__DIR__).'/Architecture/Support/rules.php';

/**
 * The stack check reads the installed JavaScript packages from whichever lockfile the project
 * keeps, because `laravel new` lets a project pick npm, pnpm or bun.
 */
describe('ruleNpmLockInstalled', function () {
    it('reads the top-level packages of package-lock.json', function () {
        $lock = ['packages' => [
            '' => ['name' => 'app'],
            'node_modules/react' => ['version' => '19.2.0'],
            'node_modules/@inertiajs/react' => ['version' => '3.7.0'],
            'node_modules/@inertiajs/react/node_modules/qs' => ['version' => '6.0.0'],
        ]];

        expect(ruleNpmLockInstalled($lock))->toBe(['react' => '19.2.0', '@inertiajs/react' => '3.7.0']);
    });
});

describe('rulePnpmInstalled', function () {
    it('reads the root importer of pnpm-lock.yaml and drops the peer suffix', function () {
        $lock = <<<'YAML'
            lockfileVersion: '9.0'

            importers:

              .:
                dependencies:
                  '@inertiajs/react':
                    specifier: ^3.0.0
                    version: 3.7.0(react-dom@19.2.8(react@19.2.8))(react@19.2.8)
                  react:
                    specifier: ^19.2.0
                    version: 19.2.8
                devDependencies:
                  eslint:
                    specifier: ^9.17.0
                    version: 9.39.1(jiti@2.7.0)

            packages:

              qs@6.0.0:
                resolution: {integrity: sha512-x}

            YAML;

        expect(rulePnpmInstalled($lock))->toBe(['@inertiajs/react' => '3.7.0', 'react' => '19.2.8', 'eslint' => '9.39.1']);
    });

    it('reads nothing from a file with no root importer', function () {
        expect(rulePnpmInstalled("lockfileVersion: '9.0'\n"))->toBe([]);
    });
});

describe('ruleBunInstalled', function () {
    it('reads the top-level packages of bun.lock despite its trailing commas', function () {
        $lock = <<<'JSONC'
            {
              "lockfileVersion": 1,
              "workspaces": {
                "": { "name": "app", },
              },
              "packages": {
                "@inertiajs/react": ["@inertiajs/react@3.7.0", "", {}, "sha512-a"],
                "react": ["react@19.2.8", "", {}, "sha512-b"],
                "@inertiajs/react/qs": ["qs@6.0.0", "", {}, "sha512-c"],
              },
            }
            JSONC;

        expect(ruleBunInstalled($lock))->toBe(['@inertiajs/react' => '3.7.0', 'react' => '19.2.8']);
    });
});

describe('ruleNpmBundledVersions', function () {
    beforeEach(function () {
        $this->nodeModules = sys_get_temp_dir().'/kit-bundled-'.getmypid().'-'.uniqid();

        $write = function (string $package, array $manifest): void {
            mkdir($this->nodeModules.'/'.$package, 0777, true);
            file_put_contents($this->nodeModules.'/'.$package.'/package.json', json_encode($manifest));
        };

        $write('vite', ['name' => '@voidzero-dev/vite-plus-core', 'version' => '1.4.0', 'bundledVersions' => ['vite' => '8.3.5', 'rolldown' => '1.2.15']]);
        $write('react', ['name' => 'react', 'version' => '19.2.0', 'bundledVersions' => ['react' => '0.0.1']]);
    });

    afterEach(function () {
        File::deleteDirectory($this->nodeModules);
    });

    it('reads an alias of a toolchain as the version it bundles', function () {
        expect(ruleNpmBundledVersions(['vite' => '1.4.0', 'react' => '19.2.0'], $this->nodeModules))
            ->toBe(['vite' => '8.3.5', 'react' => '19.2.0']);
    });

    it('keeps the lockfile\'s version without node_modules', function () {
        expect(ruleNpmBundledVersions(['vite' => '1.4.0'], $this->nodeModules.'/missing'))->toBe(['vite' => '1.4.0']);
    });
});
