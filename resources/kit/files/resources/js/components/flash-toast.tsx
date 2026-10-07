import { useFlashToast } from '@/hooks/use-flash-toast';

/**
 * ตัวฟัง flash `toast` จาก Inertia — ไม่เรนเดอร์อะไร อยู่เพื่อให้ hook มีที่เรียก
 * ระดับ app ใน `withApp()` ซึ่งไม่ใช่ component จึงเรียก hook ตรง ๆ ไม่ได้
 */
export function FlashToast() {
    useFlashToast();

    return null;
}
