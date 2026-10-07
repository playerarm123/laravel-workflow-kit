import type { DtQuery } from './use-data-table';

export type ListQueryOptions<TQuery extends object> = {
    /** ค่าปัจจุบันจาก props — เจ้าของ state คือ URL ไม่ใช่หน้าจอ */
    query: TQuery;
    /**
     * ทางออกสู่ server ทางเดียวของหน้า — hook merge ทุกการเปลี่ยนเข้ากับ `query` และตัดค่าว่าง
     * ให้ก่อนเรียก จึงไม่มีจุดไหนลืมพาพารามิเตอร์ตัวอื่นไปด้วย ไม่พา `page` เด็ดขาด —
     * ชุดผลลัพธ์ใหม่มีจำนวนหน้าไม่เท่าเดิม
     */
    visit: (query: TQuery) => void;
};

/** สิ่งที่ toolbar ต้องรู้จากหน้า list — ทั้งหน้า table (`useDataTable`) และหน้า grid คืนรูปนี้ */
export type ListQuery<TQuery extends object> = {
    /** ค่าปัจจุบันตามที่หน้าเพจส่งมา — toolbar อ่านค่าตัวกรองที่กำลังใช้จากตรงนี้ */
    query: TQuery;
    /** ให้ toolbar เรียก `fetch({ search })` — hook รวมกับค่าปัจจุบันเอง */
    fetch: (next: Partial<TQuery>) => void;
    /** มีตัวกรองตัวใดมีค่าอยู่ — ให้ toolbar โชว์ปุ่มล้าง */
    hasFilters: boolean;
};

/** คีย์ของ DtQuery ที่ไม่ใช่ตัวกรอง — toolbar ใช้แยกว่าคีย์ไหนต้องล้าง */
export const DT_QUERY_KEYS: ReadonlySet<string> = new Set<keyof DtQuery>([
    'sort',
    'direction',
    'per_page',
]);

/** คีย์ที่เหลือจาก DtQuery คือตัวกรอง — `''` (search) และ `null` (select) แปลว่าไม่กรอง */
function hasActiveFilters(query: object): boolean {
    return Object.entries(query).some(
        ([key, value]) =>
            !DT_QUERY_KEYS.has(key) && value !== '' && value !== null,
    );
}

/**
 * ตัดคีย์ที่เป็นสตริงว่างออกจาก URL (`search=` เปล่า) — Wayfinder ตัด `null`/`undefined`
 * ให้อยู่แล้วแต่ไม่ตัด `''` cast กลับเป็น TQuery เพราะ `search: string` ไม่รับ undefined
 * แต่ฝั่งรับคือ query string ที่คีย์หายได้อยู่แล้ว
 */
function compactQuery<TQuery extends object>(query: TQuery): TQuery {
    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => value !== ''),
    ) as TQuery;
}

/**
 * query บน URL ของหน้า list กับทางยิงทางเดียว — หน้า grid (`<InfiniteScroll>`) ใช้ตรง ๆ
 * หน้า table ได้ผ่าน `useDataTable` ซึ่งเรียก hook นี้ข้างใน
 */
export function useListQuery<TQuery extends object>({
    query,
    visit,
}: ListQueryOptions<TQuery>): ListQuery<TQuery> {
    const fetch = (next: Partial<TQuery>): void => {
        visit(compactQuery({ ...query, ...next }));
    };

    return {
        query,
        fetch,
        hasFilters: hasActiveFilters(query),
    };
}
