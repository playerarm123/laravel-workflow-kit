import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import type { SharedProps } from '@/types';

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
        (key: string, replace: Record<string, string | number> = {}) => {
            const line = translations[key] ?? key;

            return Object.entries(replace).reduce(
                (carry, [token, value]) =>
                    carry.replaceAll(`:${token}`, String(value)),
                line,
            );
        },
        [translations],
    );

    return { t, locale, timezone, currency };
}
