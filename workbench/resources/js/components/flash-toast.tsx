import { useFlashToast } from '@/hooks/use-flash-toast';

/**
 * Listens for Inertia's `toast` flash. It renders nothing; it exists so the hook has an app-level
 * place to be called from, because `withApp()` is not a component and cannot call a hook directly.
 */
export function FlashToast() {
    useFlashToast();

    return null;
}
