import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';
import type { SharedProps } from '@/types';

/**
 * อ่านคำแปลที่ HandleInertiaRequests แชร์มากับทุก response
 *
 * คีย์ที่ไม่มีคำแปลจะคืนตัวคีย์เอง — หน้าจอจึงไม่เคยว่าง และคีย์ที่โผล่มาดิบ ๆ
 * คือสัญญาณว่าลืมเติมคำแปล ไม่ใช่บั๊กที่ทำให้แอปพัง
 */
export function useTranslation() {
    const { locale, timezone, currency, translations } =
        usePage<SharedProps>().props;

    const t = useCallback(
        (key: string, replace: Record<string, string | number> = {}) => {
            const line = translations[key] ?? key;

            return Object.entries(replace).reduce(
                (carry, [token, value]) =>
                    carry.replaceAll(`:${token}`, String(value)),
                line,
            );
        },
        [translations],
    );

    return { t, locale, timezone, currency };
}
