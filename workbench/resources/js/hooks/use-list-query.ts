import type { DtQuery } from './use-data-table';

export type ListQueryOptions<TQuery extends object> = {
    /** The current value from props. The URL owns the state, not the screen */
    query: TQuery;
    /**
     * The page's one way to the server. The hook merges every change into `query` and drops empty values
     * before the call, so nothing forgets to carry the other parameters. It never carries `page`:
     * a new result set has a different page count.
     */
    visit: (query: TQuery) => void;
};

/** What a toolbar needs from a list page. Both a table page (`useDataTable`) and a grid page return this shape */
export type ListQuery<TQuery extends object> = {
    /** The current value as the page passed it. The toolbar reads the active filter values from here */
    query: TQuery;
    /** The toolbar calls `fetch({ search })`; the hook merges it with the current value */
    fetch: (next: Partial<TQuery>) => void;
    /** Whether any filter has a value, so the toolbar shows the clear button */
    hasFilters: boolean;
};

/** DtQuery keys that are not filters. The toolbar uses them to tell which keys to clear */
export const DT_QUERY_KEYS: ReadonlySet<string> = new Set<keyof DtQuery>([
    'sort',
    'direction',
    'per_page',
]);

/** The keys left over from DtQuery are filters. `''` (search) and `null` (select) mean no filter */
function hasActiveFilters(query: object): boolean {
    return Object.entries(query).some(
        ([key, value]) =>
            !DT_QUERY_KEYS.has(key) && value !== '' && value !== null,
    );
}

/**
 * Drops keys holding an empty string from the URL (a bare `search=`). Wayfinder already drops `null`/`undefined`
 * but not `''`. Cast back to TQuery because `search: string` does not accept undefined,
 * but the receiving end is a query string, where a key may be missing anyway.
 */
function compactQuery<TQuery extends object>(query: TQuery): TQuery {
    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => value !== ''),
    ) as TQuery;
}

/**
 * The list page's URL query and its one way to send. A grid page (`<InfiniteScroll>`) uses it directly;
 * a table page gets it through `useDataTable`, which calls this hook inside.
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
