import { overridesFor } from './Support/rules.js';

/**
 * The ESLint half of stack.md's "One library per need", spread into eslint.config.js.
 *
 * Every message starts with `[stack:<check>]`. rule-overrides.json exempts a file from a check
 * (`rule: stack`, `subject`: the file's path from the project root), read through
 * `overridesFor()` in ./Support/rules.js.
 *
 * shadcn's registry imports `cn` from the `cn` package, and the shadcn CLI depends on that
 * package itself, so node_modules/cn is there in every project and a pasted import builds. The
 * stack merges class names with `cn()` from @/lib/utils. eslint.config.js ignores
 * resources/js/components/ui, so tests/Architecture/StackTest.php checks the same import there.
 *
 * Proven against fixtures by tests/Architecture/StackEslintTest.php.
 */
const see = "See stack.md in the workflow kit's guidelines";
const exempted = overridesFor('stack');

const plugin = {
    rules: {
        'cn-import': {
            meta: { type: 'problem', schema: [] },
            create(context) {
                const report = (node) =>
                    context.report({
                        node,
                        message: `[stack:cn-import] Import cn from @/lib/utils (clsx + tailwind-merge), never from the cn package — add shadcn components with \`php artisan kit:ui-add\`, which rewrites the import. ${see}`,
                    });
                const check = (node) => {
                    if (node.source?.value === 'cn') {
                        report(node.source);
                    }
                };

                return {
                    ImportDeclaration: check,
                    ExportNamedDeclaration: check,
                    ExportAllDeclaration: check,
                    ImportExpression(node) {
                        if (node.source.type === 'Literal' && node.source.value === 'cn') {
                            report(node.source);
                        }
                    },
                };
            },
        },
    },
};

/** @type {import('eslint').Linter.Config[]} */
export default [
    {
        name: 'stack/plugin',
        files: ['resources/js/**/*.{ts,tsx}'],
        plugins: { stack: plugin },
    },
    {
        name: 'stack/cn-import',
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: [...exempted('cn-import')],
        rules: { 'stack/cn-import': 'error' },
    },
];
