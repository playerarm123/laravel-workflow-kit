import type { Auth } from '@/types/auth';
import type { MenuEntry } from '@/types/ui';

/**
 * The props HandleInertiaRequests::share() sends with every response.
 *
 * Always pass it to usePage as a generic (`usePage<SharedProps>()`): Inertia's default page props
 * carry an open index signature, which turns a named prop into `unknown` when the two intersect.
 */
export type SharedProps = {
    name: string;
    auth: Auth;
    sidebarOpen: boolean;
    locale: string;
    /** @see config/app.php — the time zone every date on the page is shown in */
    timezone: string;
    /** @see config/app.php — the currency formatMoney() shows every amount in */
    currency: string;
    translations: Record<string, string>;
    /** @see app/Http/Navigation.php — the main menu, already narrowed to what the user may open */
    menu: MenuEntry[];
};
