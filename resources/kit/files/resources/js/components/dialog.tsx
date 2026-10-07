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
    /** body — วางตรงใต้ header ใน grid ของ DialogContent เหมือน `<dl>` ใน user-detail-dialog */
    children?: ReactNode;
    /** ห่อด้วย DialogFooter ให้ ไม่ส่งมาก็ไม่มีแถบ footer */
    footer?: ReactNode;
};

/**
 * dialog สำหรับดูข้อมูล: หัวเรื่องคงที่ ส่วน body กับ footer หน้าเพจประกอบเอง
 * state เปิด/ปิดอยู่กับ `useItemDialog` ของหน้าเพจ ที่นี่แค่รับ `open` / `onOpenChange`
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
    /** ข้อความปุ่มยืนยัน — บอกการกระทำจริง เช่น "ลบ" ไม่ใช่ "ตกลง" */
    confirmLabel: string;
    /** default `t('common.cancel')` */
    cancelLabel?: string;
    onConfirm: () => void;
    onCancel: () => void;
    /** ปุ่มยืนยันเป็น variant destructive (ลบ) — default false */
    destructive?: boolean;
};

/**
 * `ViewDialog` ที่ footer เป็นปุ่มยกเลิก + ยืนยันเสมอ — คู่กับ `useConfirmDialog`
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
    /** default `t('common.confirm_delete_title')` — หน้าเพจอ่าน `dialog.target` มาใส่ชื่อได้ */
    title?: string;
    /** default `t('common.confirm_delete_description')` */
    description?: string;
};

/**
 * `ConfirmDialog` ที่ผูกกับ `useConfirmDialog` ของการลบไว้ครบ — หน้าเพจเรนเดอร์ตัวนี้เอง
 * ข้าง `<DataTable>` แทนที่จะให้ตารางเรนเดอร์ให้ เพราะข้อความยืนยันเป็นของแต่ละหน้า
 * และ how อื่น (`view.dialog`, `custom.confirm`) หน้าเพจก็เรนเดอร์เองอยู่แล้ว
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
