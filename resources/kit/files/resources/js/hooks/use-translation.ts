import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import type { SharedProps } from '@/types';

/**
 * Fills a line's `:token` placeholders in one pass. A token is matched whole, so `:to` never
 * eats the start of `:total`; a token with no value stays as it is.
 */
export function translate(
    line: string,
    replace: Record<string, string | number> = {},
): string {
    return line.replace(/:([A-Za-z_][A-Za-z0-9_]*)/g, (match, token: string) =>
        Object.hasOwn(replace, token) ? String(replace[token]) : match,
    );
}

/**
 * Reads the translations HandleInertiaRequests shares with every response.
 *
 * A key with no translation returns the key itself, so the screen is never blank, and a raw key
 * on screen signals a missing translation, not a bug that crashes the app.
 */
export function useTranslation() {
    const { locale, timezone, currency, translations } =
        usePage<SharedProps>().props;

    const t = useCallback(
        (key: string, replace: Record<string, string | number> = {}) =>
            translate(translations[key] ?? key, replace),
        [translations],
    );

    return { t, locale, timezone, currency };
}
