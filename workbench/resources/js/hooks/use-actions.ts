import { router } from '@inertiajs/react';
import { Edit, Eye, Trash } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { DtRowData } from '@/types';
import type { RouteDefinition } from '@/wayfinder';
import type { ConfirmDialog, ItemDialog } from './use-dialog';
import { useTranslation } from './use-translation';

/**
 * The source of CRUD actions for row/toolbar buttons. Call `useActions()` once,
 * then build as many buttons as you need from that instance: `actions.<kind>.<how>(params)`
 *
 * - kind = which button (`view` `edit` `delete` come with title/icon/variant; `custom` takes its own)
 * - how  = what a click does (`page` navigates, `dialog`/`confirm` open a dialog, `click` is your own)
 *
 * These methods are not hooks. The only hook used is `useTranslation`, when the instance is built; the methods
 * are closures over `t`, so they can be called freely in an object literal or a condition. The trade-off is that
 * a method cannot create state, so a dialog must come from the page (`useItemDialog` / `useConfirmDialog`),
 * which matches the page already holding the dialog to render `<ViewDialog>` itself.
 */

export type ActionVariant = 'default' | 'destructive';

/** `navigate` (default) = an Inertia visit in the same tab, `new-tab` = opens a new tab */
export type PageMode = 'navigate' | 'new-tab';

type Preset = {
    title: string;
    icon: LucideIcon;
    variant: ActionVariant;
};

/**
 * `visible` accepts a function of the row, for a button shown by that row's state (e.g. "Deactivate"
 * only on active rows). Declare 2 actions with opposite visibility, not one action that changes
 * title/icon per row, because the dropdown uses the title as its key.
 */
export type ActionVisibility<TData extends DtRowData> =
    boolean | ((data: TData) => boolean);

type BaseParams<TData extends DtRowData> = {
    title?: string;
    icon?: LucideIcon;
    /** default `true` */
    iconOnly?: boolean;
    /** default `true` */
    visible?: ActionVisibility<TData>;
    /** Default `false`. The button still shows but cannot be clicked (waiting on the backend / temporarily no permission) */
    disabled?: boolean;
    /** Default by kind (`delete` is destructive) */
    variant?: ActionVariant;
};

/** A kind with no preset: the caller says what the button is */
type CustomBaseParams<TData extends DtRowData> = BaseParams<TData> & {
    title: string;
    icon: LucideIcon;
};

export type PageParams<
    TData extends DtRowData,
    TBase extends BaseParams<TData> = BaseParams<TData>,
> = TBase & {
    route: (data: TData) => RouteDefinition<'get'>;
    mode?: PageMode;
};

export type DialogParams<
    TData extends DtRowData,
    TBase extends BaseParams<TData> = BaseParams<TData>,
> = TBase & {
    dialog: ItemDialog<TData>;
};

export type ConfirmParams<
    TData extends DtRowData,
    TBase extends BaseParams<TData> = BaseParams<TData>,
> = TBase & {
    dialog: ConfirmDialog<TData>;
};

export type ClickParams<
    TData extends DtRowData,
    TBase extends BaseParams<TData> = BaseParams<TData>,
> = TBase & {
    click: (data: TData) => void;
};

export type Action<TData extends DtRowData> = {
    title: string;
    icon: LucideIcon;
    iconOnly: boolean;
    visible: ActionVisibility<TData>;
    disabled: boolean;
    variant: ActionVariant;
    click: (data: TData) => void;
};

/** Resolve `visible` per row. The table calls it while rendering each row */
export function isActionVisible<TData extends DtRowData>(
    action: Action<TData>,
    data: TData,
): boolean {
    return typeof action.visible === 'function'
        ? action.visible(data)
        : action.visible;
}

export type DialogAction<TData extends DtRowData> = Action<TData> & {
    dialog: ItemDialog<TData>;
};

export type ConfirmAction<TData extends DtRowData> = Action<TData> & {
    dialog: ConfirmDialog<TData>;
};

export type KindActions<
    TData extends DtRowData,
    TBase extends BaseParams<TData> = BaseParams<TData>,
> = {
    page: (params: PageParams<TData, TBase>) => Action<TData>;
    dialog: (params: DialogParams<TData, TBase>) => DialogAction<TData>;
    confirm: (params: ConfirmParams<TData, TBase>) => ConfirmAction<TData>;
    click: (params: ClickParams<TData, TBase>) => Action<TData>;
};

export type Actions<TData extends DtRowData> = {
    view: KindActions<TData>;
    edit: KindActions<TData>;
    delete: KindActions<TData>;
    custom: KindActions<TData, CustomBaseParams<TData>>;
};

const presets = {
    view: { titleKey: 'common.action_view', icon: Eye, variant: 'default' },
    edit: { titleKey: 'common.action_edit', icon: Edit, variant: 'default' },
    delete: {
        titleKey: 'common.action_delete',
        icon: Trash,
        variant: 'destructive',
    },
} as const satisfies Record<
    string,
    { titleKey: string; icon: LucideIcon; variant: ActionVariant }
>;

function toPage<TData extends DtRowData>(
    route: (data: TData) => RouteDefinition<'get'>,
    mode: PageMode,
): (data: TData) => void {
    if (mode === 'new-tab') {
        return (data) => {
            window.open(route(data).url, '_blank', 'noopener');
        };
    }

    return (data) => router.get(route(data));
}

/**
 * Merge defaults → action: preset first, params over it, then click last.
 * `custom` has no preset, but its params type already requires title/icon, so no value ends up undefined.
 */
function build<TData extends DtRowData>(
    preset: Preset | undefined,
    params: BaseParams<TData>,
    click: (data: TData) => void,
): Action<TData> {
    const merged = { ...preset, ...params } as Preset & BaseParams<TData>;

    return {
        title: merged.title,
        icon: merged.icon,
        iconOnly: merged.iconOnly ?? true,
        visible: merged.visible ?? true,
        disabled: merged.disabled ?? false,
        variant: merged.variant ?? 'default',
        click,
    };
}

/** The kind × how table lives here alone, so a new how reaches every kind at once */
function forKind<TData extends DtRowData, TBase extends BaseParams<TData>>(
    preset: Preset | undefined,
): KindActions<TData, TBase> {
    return {
        page: ({ route, mode = 'navigate', ...params }) =>
            build(preset, params, toPage(route, mode)),
        dialog: ({ dialog, ...params }) => ({
            ...build(preset, params, dialog.open),
            dialog,
        }),
        confirm: ({ dialog, ...params }) => ({
            ...build(preset, params, dialog.open),
            dialog,
        }),
        click: ({ click, ...params }) => build(preset, params, click),
    };
}

export function useActions<TData extends DtRowData>(): Actions<TData> {
    const { t } = useTranslation();

    const preset = (kind: keyof typeof presets): Preset => ({
        title: t(presets[kind].titleKey),
        icon: presets[kind].icon,
        variant: presets[kind].variant,
    });

    return {
        view: forKind(preset('view')),
        edit: forKind(preset('edit')),
        delete: forKind(preset('delete')),
        custom: forKind(undefined),
    };
}
