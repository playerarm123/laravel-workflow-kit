/**
 * The shape Laravel's LengthAwarePaginator really serializes to: flat, as one object.
 * Not the `{ data, meta }` of an API Resource, which is a different shape.
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
