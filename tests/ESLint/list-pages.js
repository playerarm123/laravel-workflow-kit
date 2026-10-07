import { internalAlias, overridesFor, selectorRule } from './Support/rules.js';

/**
 * The ESLint half of list-pages.md, spread into eslint.config.js.
 *
 * Every message starts with `[list-pages:<check>]`. rule-overrides.json exempts a file from a check
 * (`rule: list-pages`, `subject`: the file's path from the project root), read through
 * `overridesFor()` in ./Support/rules.js.
 *
 * The checks are rules of a small plugin, not `no-restricted-syntax` or `no-restricted-imports`
 * entries: flat config keeps only the last options of a rule for a file, so a second entry over
 * the same files would silently replace another rule's own.
 *
 * Proven against fixtures by tests/Architecture/ListPagesEslintTest.php.
 */
const see = "See list-pages.md in the workflow kit's guidelines";
const exempted = overridesFor('list-pages');

/** The kit itself, which is where the table and the router calls are meant to live. */
const kitFiles = [
    'resources/js/hooks/use-list-query.ts',
    'resources/js/hooks/use-data-table.tsx',
    'resources/js/hooks/use-data-table-toolbar.ts',
    'resources/js/hooks/use-actions.ts',
    'resources/js/hooks/use-dialog.ts',
    'resources/js/hooks/use-translation.ts',
    'resources/js/components/dt-table.tsx',
    'resources/js/components/dt-toolbar.tsx',
    'resources/js/components/dialog.tsx',
    'resources/js/components/buttons.tsx',
    'resources/js/components/icons.tsx',
    'resources/js/components/heading.tsx',
    'resources/js/components/ui/**',
];

const oneTableHook = {
    selector:
        "ImportDeclaration[source.value='@tanstack/react-table'] ImportSpecifier[imported.name=/^(useTable|useReactTable)$/]",
    message: `[list-pages:one-table] Build a table with useDataTable() instead. ${see}`,
};

const plugin = {
    rules: {
        'visit-only': selectorRule([
            {
                selector:
                    "CallExpression[callee.object.name='router'][callee.property.name='get']:not(Property[key.name='visit'] CallExpression)",
                message: `[list-pages:visit-only] A page calls router.get only inside the \`visit\` it hands to useDataTable or useListQuery, so every change keeps the rest of the query. ${see}`,
            },
        ]),
        'toolbar-state': selectorRule([
            {
                selector: "ImportSpecifier[imported.name='router']",
                message: `[list-pages:toolbar-state] A domain toolbar never calls the router — DataTableToolbar fetches through \`dt\`. ${see}`,
            },
            {
                selector:
                    'CallExpression[callee.name=/^(useState|useEffect|setTimeout)$/]',
                message: `[list-pages:toolbar-state] A domain toolbar holds no state, effect or debounce — DataTableToolbar already does. Pass \`fields\` only. ${see}`,
            },
        ]),
        'one-table': selectorRule([oneTableHook]),
        'one-table-markup': selectorRule([
            {
                selector:
                    "ImportDeclaration[source.value='@/components/ui/table']",
                message: `[list-pages:one-table] A page renders rows through <DataTable dt>, not the table markup. ${see}`,
            },
        ]),
    },
};

/** @type {import('eslint').Linter.Config[]} */
export default [
    internalAlias,
    {
        name: 'list-pages/plugin',
        files: ['resources/js/**/*.{ts,tsx}'],
        plugins: { 'list-pages': plugin },
    },
    {
        name: 'list-pages/visit-only',
        files: ['resources/js/pages/**/*.{ts,tsx}'],
        ignores: exempted('visit-only'),
        rules: { 'list-pages/visit-only': 'error' },
    },
    {
        name: 'list-pages/toolbar-state',
        files: ['resources/js/components/*/table-toolbar.tsx'],
        ignores: exempted('toolbar-state'),
        rules: { 'list-pages/toolbar-state': 'error' },
    },
    {
        name: 'list-pages/one-table',
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: [...kitFiles, ...exempted('one-table')],
        rules: { 'list-pages/one-table': 'error' },
    },
    {
        name: 'list-pages/one-table-markup',
        files: ['resources/js/pages/**/*.{ts,tsx}'],
        ignores: exempted('one-table'),
        rules: { 'list-pages/one-table-markup': 'error' },
    },
];
