import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { ExternalToast } from 'sonner';
import type { FlashToast } from '@/types/ui';

/**
 * error ค้างจนผู้ใช้กดปิดเอง — ข้อความว่าทำไม่ได้ต้องไม่หายไปก่อนได้อ่าน
 * type อื่นใช้เวลา default ของ sonner
 */
const TOAST_OPTIONS: Partial<Record<FlashToast['type'], ExternalToast>> = {
    error: { duration: Infinity, closeButton: true },
};

/**
 * หัวข้อและข้อความมาจาก PHP แปลเสร็จแล้ว (`App\Http\FlashToast`)
 *
 * hook นี้ถูกเรียกจาก `withApp()` ซึ่งอยู่นอก Inertia context จึงใช้ `usePage` /
 * `useTranslation` ไม่ได้ และจะพึ่ง event `navigate` ก็ไม่ได้ เพราะ redirect กลับ
 * URL เดิม (เช่นหลังลบ) เป็น replace swap ที่ Inertia ไม่ยิง `navigate` ให้
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
