export type DtRowData = {
    id: string;
};

/**
 * ตัวกรองที่ทุกหน้า list มีเหมือนกัน — คีย์ตรง `search` + `DateRange::toFilters()` ใน
 * `Criteria::toFilters()` ฝั่ง PHP วันที่เป็น `YYYY-MM-DD` (วันตาม timezone ของแอป) หรือ
 * `null` เมื่อไม่กรอง หน้าเพจขยายด้วยตัวกรองของโดเมน: `DtFilters & { role: … }`
 *
 * @see app/Application/Concerns/DateRange.php
 */
export type DtFilters = {
    search: string;
    created_from: string | null;
    created_to: string | null;
};
