import { overridesFor } from './Support/rules.js';

/**
 * The ESLint half of actions.md, spread into eslint.config.js.
 *
 * Every message starts with `[actions:<check>]`. rule-overrides.json exempts a file from a check
 * (`rule: actions`, `subject`: the file's path from the project root), read through
 * `overridesFor()` in ./Support/rules.js.
 *
 * The checks are rules of a small plugin, not `no-restricted-syntax` entries: flat config keeps
 * only the last options of a rule for a file, so a second `no-restricted-syntax` over the same
 * files would silently replace another rule's own.
 *
 * Proven against fixtures by tests/Architecture/ActionsEslintTest.php.
 */
const see = "See actions.md in the workflow kit's guidelines";
const exempted = overridesFor('actions');

/** The Inertia router's methods that visit a url. */
const visits = new Set(['get', 'post', 'put', 'patch', 'delete', 'visit']);

/**
 * The method of a `router.{method}(…)` call, or null for any other call.
 *
 * @param {import('estree').CallExpression} node
 * @returns {string | null}
 */
function routerMethod(node) {
    const callee = node.callee;

    if (
        callee.type !== 'MemberExpression' ||
        callee.computed ||
        callee.object.type !== 'Identifier' ||
        callee.object.name !== 'router' ||
        callee.property.type !== 'Identifier'
    ) {
        return null;
    }

    return callee.property.name;
}

/**
 * A rule that reports every `router.{method}(…)` call the predicate picks.
 *
 * @param {(method: string, node: import('estree').CallExpression) => boolean} reports
 * @param {string} message
 * @returns {import('eslint').Rule.RuleModule}
 */
function routerCallRule(reports, message) {
    return {
        meta: { type: 'problem', schema: [] },
        create(context) {
            return {
                CallExpression(node) {
                    const method = routerMethod(node);

                    if (method !== null && reports(method, node)) {
                        context.report({ node, message });
                    }
                },
            };
        },
    };
}

const plugin = {
    rules: {
        'post-only': routerCallRule(
            (method) => method === 'patch' || method === 'put',
            `[actions:post-only] An update posts through <Form {...update.form(model)}> and an action is a POST — never router.patch() or router.put(). ${see}`,
        ),
        'action-home': routerCallRule(
            (method) => method === 'post',
            `[actions:action-home] A page never calls an action — call it from a component in components/{aggregate}/. ${see}`,
        ),
        'wayfinder-url': routerCallRule(
            (method, node) =>
                visits.has(method) &&
                (node.arguments[0]?.type === 'TemplateLiteral' ||
                    (node.arguments[0]?.type === 'Literal' &&
                        typeof node.arguments[0].value === 'string')),
            `[actions:wayfinder-url] Take the url from Wayfinder (action.url(model)), so a renamed route fails the build. ${see}`,
        ),
    },
};

/** @type {import('eslint').Linter.Config[]} */
export default [
    {
        name: 'actions/plugin',
        files: ['resources/js/**/*.{ts,tsx}'],
        plugins: { actions: plugin },
    },
    {
        name: 'actions/post-only',
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: exempted('post-only'),
        rules: { 'actions/post-only': 'error' },
    },
    {
        name: 'actions/action-home',
        files: ['resources/js/pages/**/*.{ts,tsx}'],
        ignores: exempted('action-home'),
        rules: { 'actions/action-home': 'error' },
    },
    {
        name: 'actions/wayfinder-url',
        files: ['resources/js/**/*.{ts,tsx}'],
        ignores: exempted('wayfinder-url'),
        rules: { 'actions/wayfinder-url': 'error' },
    },
];
