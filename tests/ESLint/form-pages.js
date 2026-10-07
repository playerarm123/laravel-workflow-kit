import { internalAlias, overridesFor, selectorRule } from './Support/rules.js';

/**
 * The ESLint half of form-pages.md, spread into eslint.config.js.
 *
 * Every message starts with `[form-pages:<check>]`. rule-overrides.json exempts a file from a check
 * (`rule: form-pages`, `subject`: the file's path from the project root), read through
 * `overridesFor()` in ./Support/rules.js.
 *
 * The checks are rules of a small plugin, not `no-restricted-syntax` entries: flat config keeps
 * only the last options of a rule for a file, so a second `no-restricted-syntax` over the same
 * files would silently replace another rule's own.
 *
 * Proven against fixtures by tests/Architecture/FormPagesEslintTest.php.
 */
const see = "See form-pages.md in the workflow kit's guidelines";
const exempted = overridesFor('form-pages');

/** The Inertia calls that would let a page or a form submit some other way than `<Form>`. */
const otherSubmits =
    "ImportDeclaration[source.value='@inertiajs/react'] ImportSpecifier[imported.name=/^(useForm|useHttp|router)$/]";

const formOnly = `[form-pages:form-only] A form component submits through <Form {...action}> only, so its inputs are what it posts. ${see}`;

const plugin = {
    rules: {
        'page-shell': selectorRule([
            {
                selector: 'JSXOpeningElement[name.name=/^(Form|form|input)$/]',
                message: `[form-pages:page-shell] A create or edit page is a shell — the fields and the submit live in components/{aggregate}/form.tsx. ${see}`,
            },
            {
                selector: otherSubmits,
                message: `[form-pages:page-shell] A create or edit page submits nothing itself — it renders the form component. ${see}`,
            },
        ]),
        'form-only': selectorRule([
            { selector: otherSubmits, message: formOnly },
            {
                selector: "CallExpression[callee.name='fetch']",
                message: formOnly,
            },
        ]),
    },
};

/** @type {import('eslint').Linter.Config[]} */
export default [
    internalAlias,
    {
        name: 'form-pages/plugin',
        files: ['resources/js/**/*.{ts,tsx}'],
        plugins: { 'form-pages': plugin },
    },
    {
        name: 'form-pages/page-shell',
        files: [
            'resources/js/pages/**/create.tsx',
            'resources/js/pages/**/edit.tsx',
        ],
        ignores: exempted('page-shell'),
        rules: { 'form-pages/page-shell': 'error' },
    },
    {
        name: 'form-pages/form-only',
        files: ['resources/js/components/*/form.tsx'],
        ignores: exempted('form-only'),
        rules: { 'form-pages/form-only': 'error' },
    },
];
