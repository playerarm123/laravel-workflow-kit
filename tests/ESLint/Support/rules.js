import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import process from 'node:process';

/**
 * What every ESLint kit file under the workflow kit's tests/ESLint shares, as
 * tests/Architecture/Support/rules.php does for the Pest checks.
 *
 * rule-overrides.json exempts a file from a check (`subject`: the file's path from the project
 * root). The entries are read on every lint run, so a new override takes effect at once. A
 * malformed entry exempts nothing — tests/Architecture/RuleOverridesTest reports it.
 * `RULE_OVERRIDES_PATH` points the read at another file, which is how each proof feeds the
 * rules an override of its own.
 *
 * The root is the directory ESLint runs from, where eslint.config.js lives. The rules ship in
 * the package, so it is never counted up from this file: vendor/ may hold the package as a
 * symlink, and Node resolves it.
 */
const root = process.cwd();

/**
 * The files rule-overrides.json exempts from one of the rule's checks.
 *
 * @param {string} rule
 * @returns {(check: string) => string[]}
 */
export function overridesFor(rule) {
    return (check) => {
        const path =
            process.env.RULE_OVERRIDES_PATH ??
            resolve(root, 'rule-overrides.json');

        if (!existsSync(path)) {
            return [];
        }

        let document;

        try {
            document = JSON.parse(readFileSync(path, 'utf8'));
        } catch {
            return [];
        }

        const entries = Array.isArray(document?.overrides)
            ? document.overrides
            : [];

        return entries
            .filter(
                (entry) =>
                    entry?.rule === rule &&
                    entry?.check === check &&
                    typeof entry?.subject === 'string' &&
                    String(entry?.reason ?? '').trim().length >= 20 &&
                    String(entry?.approved_by ?? '').trim() !== '' &&
                    /^\d{4}-\d{2}-\d{2}$/.test(String(entry?.date ?? '')),
            )
            .map((entry) => entry.subject);
    };
}

/**
 * A rule that reports every node one of its esquery selectors matches, with that selector's message.
 *
 * @param {{ selector: string, message: string }[]} entries
 * @returns {import('eslint').Rule.RuleModule}
 */
export function selectorRule(entries) {
    return {
        meta: { type: 'problem', schema: [] },
        create(context) {
            return Object.fromEntries(
                entries.map(({ selector, message }) => [
                    selector,
                    (node) => context.report({ node, message }),
                ]),
            );
        },
    };
}

/**
 * The name of a non-computed member's property, or null.
 *
 * @param {import('estree').Node} node
 * @returns {string | null}
 */
export function propertyName(node) {
    if (
        node.type !== 'MemberExpression' ||
        node.computed ||
        node.property.type !== 'Identifier'
    ) {
        return null;
    }

    return node.property.name;
}

/**
 * Ranks every `@/` import as internal, resolved or not, so a page a generator just wrote passes
 * import/order before `wayfinder:generate` has written the routes and actions it imports.
 *
 * Flat config merges `settings` across the configs a file matches, so this joins the project's
 * own `import/resolver` instead of replacing it.
 */
export const internalAlias = {
    name: 'rules/internal-alias',
    files: ['resources/js/**/*.{ts,tsx}'],
    settings: { 'import/internal-regex': '^@/' },
};
