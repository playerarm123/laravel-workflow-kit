import { useState } from 'react';

/**
 * hook สำหรับ dialog ที่ไม่รู้จักโดเมนไหนเลย — ตัวที่ผูกกับโดเมน (เช่น status dialog
 * ของ lottery type) ไปอยู่กับ component ของมันเอง ไม่เข้ามาที่นี่
 *
 * ทุกตัวใช้ API ชุดเดียวกัน: `open` / `close` / `onOpenChange` ต่างกันแค่ `open`
 * รับอะไร ผู้ใช้จึงจำแบบเดียวแล้วใช้ได้ทั้งหมด
 *
 * hook ถือแค่ state ไม่ถือเนื้อหา — หัวเรื่อง body footer เป็นของ component ที่เรนเดอร์
 * (ดู `ViewDialog`) จะได้ไม่ต้องส่ง component ผ่าน hook ไปหา item ที่มันต้องการอีกที
 */

export type ItemDialog<TItem> = {
    /** item ที่ dialog กำลังแสดงอยู่ — `null` คือปิด */
    target: TItem | null;
    open: (item: TItem) => void;
    close: () => void;
    /** ต่อเข้า `onOpenChange` ของ Dialog ได้ตรง ๆ — ทางนี้ปิดได้อย่างเดียว การเปิดต้องมาจาก `open` พร้อม item */
    onOpenChange: (isOpen: boolean) => void;
};

/**
 * dialog ที่เปิดกับ item ตัวหนึ่ง (detail, form แก้ไข): เปิด = มี target, ปิด = ไม่มี
 *
 * ไม่มี `isOpen` แยก — ถ้ามีจะเกิดสถานะ "เปิดอยู่แต่ไม่รู้ว่าของใคร" ได้ ส่วน `target`
 * ตัวเดียวตอบทั้งสองคำถาม
 *
 * ผู้เรียกส่ง `open` ลงไปให้จุดที่กด (ปุ่มในแถว, ในการ์ด) แทน setState ตรง ๆ — setter
 * บอกแค่ว่า state เปลี่ยน แต่ไม่บอกว่า state นั้นคุมอะไร ส่วน `open` ของ object ที่ตั้งชื่อ
 * ตาม dialog ไล่กลับมาหาจุดเรนเดอร์ได้จากชื่อเดียว
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
    /** ยืนยันกับ target ปัจจุบันแล้วปิด — ไม่มี target ก็ไม่ทำอะไร */
    confirm: () => void;
};

/**
 * dialog ยืนยันการกระทำกับ item ตัวหนึ่ง (ลบ, เปลี่ยนสถานะ) — คือ `ItemDialog`
 * บวกปุ่มยืนยันที่รู้เองว่าจะส่ง item ไหนให้ `onConfirm`
 *
 * ปิดก่อนแล้วค่อยเรียก `onConfirm` — ถ้า `onConfirm` โยน error dialog ก็ไม่ค้างเปิด
 * และ `onConfirm` มักเป็น visit ที่จะพา props ใหม่มาแทน target อยู่แล้ว
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
