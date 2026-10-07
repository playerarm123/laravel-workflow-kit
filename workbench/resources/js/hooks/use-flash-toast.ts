import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { ExternalToast } from 'sonner';
import type { FlashToast } from '@/types/ui';

/**
 * An error stays until the user closes it: a message saying something failed must not vanish before it is read.
 * Other types use sonner's default duration.
 */
const TOAST_OPTIONS: Partial<Record<FlashToast['type'], ExternalToast>> = {
    error: { duration: Infinity, closeButton: true },
};

/**
 * The title and message come from PHP, already translated (`App\Http\FlashToast`).
 *
 * This hook is called from `withApp()`, which sits outside the Inertia context, so it cannot use `usePage` /
 * `useTranslation`, nor rely on the `navigate` event, because a redirect back to the
 * same URL (e.g. after a delete) is a replace swap for which Inertia fires no `navigate`.
 */
export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data) {
                return;
            }

            toast[data.type](data.title, {
                description: data.message,
                ...TOAST_OPTIONS[data.type],
            });
        });
    }, []);
}
