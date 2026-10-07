/**
 * รูปร่างที่ Laravel LengthAwarePaginator serialize ออกมาจริง — แบนทั้งก้อน
 * ไม่ใช่ { data, meta } แบบ API Resource ซึ่งเป็นคนละรูป
 */
export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<TItem> = {
    data: TItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
    first_page_url: string | null;
    last_page_url: string | null;
    next_page_url: string | null;
    prev_page_url: string | null;
    path: string;
};

export type PaginationMeta = Omit<Paginated<unknown>, 'data'>;

export type SortDirection = 'asc' | 'desc';

export type SortState = {
    column: string;
    direction: SortDirection;
};
