import { router } from '@inertiajs/react';
import { Edit, Eye, Trash } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { DtRowData } from '@/types';
import type { RouteDefinition } from '@/wayfinder';
import type { ConfirmDialog, ItemDialog } from './use-dialog';
import { useTranslation } from './use-translation';

/**
 * แหล่งรวม action ของ CRUD สำหรับปุ่มในแถว/toolbar — เรียก `useActions()` ครั้งเดียว
 * แล้วสร้างปุ่มกี่ตัวก็ได้จาก instance นั้น: `actions.<kind>.<how>(params)`
 *
 * - kind = ปุ่มอะไร (`view` `edit` `delete` มี title/icon/variant ให้; `custom` ต้องส่งเอง)
 * - how  = กดแล้วเกิดอะไร (`page` navigate, `dialog`/`confirm` เปิด dialog, `click` กำหนดเอง)
 *
 * method พวกนี้ไม่ใช่ hook — hook เดียวที่ใช้คือ `useTranslation` ตอนสร้าง instance ส่วน method
 * เป็น closure ที่ปิด `t` ไว้ จึงเรียกใน object literal หรือเงื่อนไขได้อิสระ ผลข้างเคียงคือ
 * method สร้าง state เองไม่ได้ dialog จึงต้องมาจากหน้าเพจ (`useItemDialog` / `useConfirmDialog`)
 * ซึ่งก็ตรงกับที่หน้าเพจต้องถือ dialog ไว้เรนเดอร์ `<ViewDialog>` เองอยู่แล้ว
 */

export type ActionVariant = 'default' | 'destructive';

/** `navigate` (default) = Inertia visit ในแท็บเดิม, `new-tab` = เปิดแท็บใหม่ */
export type PageMode = 'navigate' | 'new-tab';

type Preset = {
    title: string;
    icon: LucideIcon;
    variant: ActionVariant;
};

/**
 * `visible` รับฟังก์ชันของแถวได้ สำหรับปุ่มที่โชว์ตามสถานะของแถวนั้น (เช่น "ปิดใช้งาน"
 * เฉพาะแถวที่ active) — ประกาศเป็น 2 action ที่ visible สลับกัน ไม่ใช่ action เดียวที่เปลี่ยน
 * title/icon ตามแถว เพราะ dropdown ใช้ title เป็น key
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
    /** default `false` — ปุ่มยังโชว์แต่กดไม่ได้ (รอ backend / ไม่มีสิทธิ์ชั่วคราว) */
    disabled?: boolean;
    /** default ตาม kind (`delete` เป็น destructive) */
    variant?: ActionVariant;
};

/** kind ที่ไม่มี preset ผู้เรียกต้องบอกเองว่าปุ่มคืออะไร */
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

/** คลี่ `visible` ต่อแถว — ตารางเรียกตอนเรนเดอร์แต่ละแถว */
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
 * merge default → action: preset ก่อน params ทับ แล้ว click ปิดท้าย
 * `custom` ไม่มี preset แต่ type ของ params บังคับ title/icon ไว้แล้ว ค่าจึงไม่หลุดเป็น undefined
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

/** ตารางคูณ kind × how อยู่ที่นี่ที่เดียว — เพิ่ม how ใหม่ทุก kind ได้พร้อมกัน */
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
