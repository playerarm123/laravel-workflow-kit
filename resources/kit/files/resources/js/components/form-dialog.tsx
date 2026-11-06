import { useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import { ViewDialog } from '@/components/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { RouteDefinition } from '@/wayfinder';

export type FormDialogProps<TValues> = {
    open: boolean;
    onOpenChange: (isOpen: boolean) => void;
    title: string;
    description?: string;
    /**
     * Wayfinder `{X}Controller.create()` or `.edit(model)`, which answers `{ defaults }` as JSON.
     * `null` while there is nothing to load (an edit dialog with no target)
     */
    load: RouteDefinition<'get'> | null;
    /** The form, given the server's starting values and `done`, which closes the dialog */
    children: (defaults: TValues, done: () => void) => ReactNode;
};

/**
 * A create or edit form in a dialog (form-pages.md, "Form dialogs"). The server owns the starting
 * values here as on a page: as the dialog opens, it asks `load` for them, and shows the form
 * once they arrive. A refused or missing row closes the dialog with an error toast.
 *
 * Loading lives here alone, so a resource's `form-dialog.tsx` only wires routes to its form.
 */
export function FormDialog<TValues>({
    open,
    onOpenChange,
    title,
    description,
    load,
    children,
}: FormDialogProps<TValues>) {
    const done = () => onOpenChange(false);

    return (
        <ViewDialog
            open={open}
            onOpenChange={onOpenChange}
            title={title}
            description={description}
        >
            {open && load !== null && (
                <Defaults<TValues> key={load.url} load={load} onFailed={done}>
                    {(defaults) => children(defaults, done)}
                </Defaults>
            )}
        </ViewDialog>
    );
}

type DefaultsProps<TValues> = {
    load: RouteDefinition<'get'>;
    onFailed: () => void;
    children: (defaults: TValues) => ReactNode;
};

/**
 * Asks for the starting values once, as it mounts. `FormDialog` keys it by url, so another row
 * mounts it again.
 */
function Defaults<TValues>({
    load,
    onFailed,
    children,
}: DefaultsProps<TValues>) {
    const { t } = useTranslation();
    const [defaults, setDefaults] = useState<TValues | null>(null);
    const started = useRef(false);
    const http = useHttp<Record<string, never>, { defaults: TValues }>({});

    useEffect(() => {
        if (started.current) {
            return;
        }

        started.current = true;

        // A refusal (403), a missing row (404) or a lost connection rejects the promise.
        http.get(load.url, {
            onSuccess: (response) => setDefaults(response.defaults),
        }).catch(() => {
            toast.error(t('common.toast_error_title'));
            onFailed();
        });
        // The values are read once, as the dialog opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    if (defaults === null) {
        return (
            <div className="flex justify-center py-8">
                <Spinner />
            </div>
        );
    }

    return children(defaults);
}
