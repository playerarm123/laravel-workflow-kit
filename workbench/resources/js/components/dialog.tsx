import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { ConfirmDialog as ConfirmDialogState } from '@/hooks/use-dialog';
import { useTranslation } from '@/hooks/use-translation';

export type ViewDialogProps = {
    open: boolean;
    onOpenChange: (isOpen: boolean) => void;
    title: string;
    description?: string;
    /** body: placed right below the header in DialogContent's grid, like the `<dl>` in user-detail-dialog */
    children?: ReactNode;
    /** Wrapped in DialogFooter for you. Leave it out and there is no footer bar */
    footer?: ReactNode;
};

/**
 * A dialog for viewing data: the header is fixed, and the page composes the body and footer itself.
 * The open/closed state lives in the page's `useItemDialog`; this only takes `open` / `onOpenChange`.
 */
export function ViewDialog({
    open,
    onOpenChange,
    title,
    description,
    children,
    footer,
}: ViewDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                {...(description === undefined
                    ? { 'aria-describedby': undefined }
                    : {})}
            >
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description !== undefined && (
                        <DialogDescription>{description}</DialogDescription>
                    )}
                </DialogHeader>

                {children}

                {footer !== undefined && <DialogFooter>{footer}</DialogFooter>}
            </DialogContent>
        </Dialog>
    );
}

export type ConfirmDialogProps = Pick<
    ViewDialogProps,
    'open' | 'onOpenChange' | 'title' | 'description' | 'children'
> & {
    /** The confirm button's text. Name the real action, e.g. "Delete", not "OK" */
    confirmLabel: string;
    /** default `t('common.cancel')` */
    cancelLabel?: string;
    onConfirm: () => void;
    onCancel: () => void;
    /** The confirm button uses the destructive variant (delete). Default false */
    destructive?: boolean;
};

/**
 * A `ViewDialog` whose footer is always cancel + confirm. Pairs with `useConfirmDialog`
 * (`onConfirm = dialog.confirm`, `onCancel = dialog.close`)
 */
export function ConfirmDialog({
    confirmLabel,
    cancelLabel,
    onConfirm,
    onCancel,
    destructive = false,
    ...viewProps
}: ConfirmDialogProps) {
    const { t } = useTranslation();

    return (
        <ViewDialog
            {...viewProps}
            footer={
                <>
                    <Button variant="outline" onClick={onCancel}>
                        {cancelLabel ?? t('common.cancel')}
                    </Button>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        onClick={onConfirm}
                    >
                        {confirmLabel}
                    </Button>
                </>
            }
        />
    );
}

export type DeleteConfirmDialogProps<TItem> = {
    dialog: ConfirmDialogState<TItem>;
    /** Default `t('common.confirm_delete_title')`. The page can read `dialog.target` to add a name */
    title?: string;
    /** default `t('common.confirm_delete_description')` */
    description?: string;
};

/**
 * A `ConfirmDialog` fully wired to a delete's `useConfirmDialog`. The page renders it itself
 * beside `<DataTable>` rather than letting the table render it, because the confirm text belongs to each page,
 * and the page already renders the other hows (`view.dialog`, `custom.confirm`) itself.
 */
export function DeleteConfirmDialog<TItem>({
    dialog,
    title,
    description,
}: DeleteConfirmDialogProps<TItem>) {
    const { t } = useTranslation();

    return (
        <ConfirmDialog
            open={dialog.target !== null}
            onOpenChange={dialog.onOpenChange}
            title={title ?? t('common.confirm_delete_title')}
            description={description ?? t('common.confirm_delete_description')}
            confirmLabel={t('common.action_delete')}
            onConfirm={dialog.confirm}
            onCancel={dialog.close}
            destructive
        />
    );
}
