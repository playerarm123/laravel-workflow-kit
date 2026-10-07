import { overridesFor, propertyName } from './Support/rules.js';

/**
 * The ESLint half of dates.md, spread into eslint.config.js.
 *
 * Every message starts with `[dates:<check>]`. rule-overrides.json exempts a file from a check
 * (`rule: dates`, `subject`: the file's path from the project root), read through
 * `overridesFor()` in ./Support/rules.js.
 *
 * The checks are rules of a small plugin, not `no-restricted-syntax` entries: flat config keeps
 * only the last options of a rule for a file, so a second `no-restricted-syntax` over the same
 * files would silently replace another rule's own.
 *
 * Proven against fixtures by tests/Architecture/DatesEslintTest.php.
 */
const see = "See dates.md in the workflow kit's guidelines";
const exempted = overridesFor('dates');

/** The one file that may format a date. */
const kitFile = 'resources/js/lib/dates.ts';

/** The methods that format with the runtime's own locale and time zone. */
const toLocale = new Set([
    'toLocaleString',
    'toLocaleDateString',
    'toLocaleTimeString',
]);

const plugin = {
    rules: {
        'intl-date': {
            meta: { type: 'problem', schema: [] },
            create(context) {
                return {
                    MemberExpression(node) {
                        if (
                            node.object.type === 'Identifier' &&
                            node.object.name === 'Intl' &&
                            propertyName(node) === 'DateTimeFormat'
                        ) {
                            context.report({
                                node,
                                message: `[dates:intl-date] Format a date through @/lib/dates, which pins its time zone — never Intl.DateTimeFormat. ${see}`,
                            });
                        }
                    },
                };
            },
        },
        'to-locale': {
            meta: { type: 'problem', schema: [] },
            create(context) {
                return {
                    CallExpression(node) {
                        const method = propertyName(node.callee);

                        if (method !== null && toLocale.has(method)) {
                            context.report({
                                node,
                                message: `[dates:to-locale] ${method}() reads the runtime's locale and time zone, so the server and the browser disagree — format a date through @/lib/dates and a number through @/lib/numbers. ${see}`,
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
        name: 'dates/plugin',
        files: ['resources/js/**/*.{ts,tsx}'],
        plugins: { dates: plugin },
    },
    {
        name: 'dates/intl-date',
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: [kitFile, ...exempted('intl-date')],
        rules: { 'dates/intl-date': 'error' },
    },
    {
        name: 'dates/to-locale',
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: [kitFile, ...exempted('to-locale')],
        rules: { 'dates/to-locale': 'error' },
    },
];
