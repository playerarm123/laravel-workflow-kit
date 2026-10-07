import { overridesFor, propertyName } from './Support/rules.js';

/**
 * The ESLint half of numbers.md, spread into eslint.config.js.
 *
 * Every message starts with `[numbers:<check>]`. rule-overrides.json exempts a file from a check
 * (`rule: numbers`, `subject`: the file's path from the project root), read through
 * `overridesFor()` in ./Support/rules.js.
 *
 * The checks are rules of a small plugin, not `no-restricted-syntax` entries: flat config keeps
 * only the last options of a rule for a file, so a second `no-restricted-syntax` over the same
 * files would silently replace another rule's own.
 *
 * Proven against fixtures by tests/Architecture/NumbersEslintTest.php.
 */
const see = "See numbers.md in the workflow kit's guidelines";
const exempted = overridesFor('numbers');

/** The one file that may format a number. */
const kitFile = 'resources/js/lib/numbers.ts';

const plugin = {
    rules: {
        'intl-number': {
            meta: { type: 'problem', schema: [] },
            create(context) {
                return {
                    MemberExpression(node) {
                        if (
                            node.object.type === 'Identifier' &&
                            node.object.name === 'Intl' &&
                            propertyName(node) === 'NumberFormat'
                        ) {
                            context.report({
                                node,
                                message: `[numbers:intl-number] Format a number through @/lib/numbers, which reads the decimals the server sent — never Intl.NumberFormat. ${see}`,
                            });
                        }
                    },
                };
            },
        },
        'to-fixed': {
            meta: { type: 'problem', schema: [] },
            create(context) {
                return {
                    CallExpression(node) {
                        if (propertyName(node.callee) === 'toFixed') {
                            context.report({
                                node,
                                message: `[numbers:to-fixed] toFixed() rounds a float and ignores the locale — format through @/lib/numbers, or add the format there. ${see}`,
                            });
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
        name: 'numbers/plugin',
        files: ['resources/js/**/*.{ts,tsx}'],
        plugins: { numbers: plugin },
    },
    {
        name: 'numbers/intl-number',
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: [kitFile, ...exempted('intl-number')],
        rules: { 'numbers/intl-number': 'error' },
    },
    {
        name: 'numbers/to-fixed',
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: [kitFile, ...exempted('to-fixed')],
        rules: { 'numbers/to-fixed': 'error' },
    },
];
