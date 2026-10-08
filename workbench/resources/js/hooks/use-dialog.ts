import { useState } from 'react';

/**
 * Hooks for dialogs that know no domain at all. A dialog tied to a domain (e.g. the status dialog
 * of a lottery type) lives with its own component, not here.
 *
 * They all share one API: `open` / `close` / `onOpenChange`, differing only in what `open`
 * takes, so a user learns one pattern and uses it for all of them.
 *
 * A hook holds only state, not content. The header, body and footer belong to the component that renders
 * (see `ViewDialog`), so no component has to pass through the hook to reach the item it needs.
 */

export type ItemDialog<TItem> = {
    /** The item the dialog is showing. `null` means closed */
    target: TItem | null;
    open: (item: TItem) => void;
    close: () => void;
    /** Plugs straight into the Dialog's `onOpenChange`. It can only close; opening must come from `open` with an item */
    onOpenChange: (isOpen: boolean) => void;
};

/**
 * A dialog opened for one item (detail, edit form): open = has a target, closed = has none.
 *
 * There is no separate `isOpen`, which would allow an "open, but for whom?" state. The single
 * `target` answers both questions.
 *
 * The caller passes `open` down to where the click happens (a row button, a card) instead of a raw setState. A setter
 * only says that state changed, not what the state controls, while `open` on an object named
 * after the dialog leads back to where it renders from one name.
 */
export function useItemDialog<TItem>(): ItemDialog<TItem> {
    const [target, setTarget] = useState<TItem | null>(null);

    return {
        target,
        open: (item) => setTarget(item),
        close: () => setTarget(null),
        onOpenChange: (isOpen) => {
            if (!isOpen) {
                setTarget(null);
            }
        },
    };
}

export type ConfirmDialog<TItem> = ItemDialog<TItem> & {
    /** Confirms with the current target, then closes. Does nothing without a target */
    confirm: () => void;
};

/**
 * A dialog that confirms an action on one item (delete, change status). It is an `ItemDialog`
 * plus a confirm button that knows which item to hand `onConfirm`.
 *
 * It closes first, then calls `onConfirm`: if `onConfirm` throws, the dialog does not stay open,
 * and `onConfirm` is usually a visit whose new props replace the target anyway.
 */
export function useConfirmDialog<TItem>(
    onConfirm: (item: TItem) => void,
): ConfirmDialog<TItem> {
    const dialog = useItemDialog<TItem>();

    return {
        ...dialog,
        confirm: () => {
            if (dialog.target === null) {
                return;
            }

            const item = dialog.target;
            dialog.close();
            onConfirm(item);
        },
    };
}
