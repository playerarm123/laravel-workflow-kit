import { usePage } from '@inertiajs/react';
import type { LucideIcon, LucideProps } from 'lucide-react';
import { DynamicIcon, iconNames } from 'lucide-react/dynamic';
import type { IconName } from 'lucide-react/dynamic';
import { forwardRef, useMemo } from 'react';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTranslation } from '@/hooks/use-translation';
import type {
    MainNavEntry,
    MainNavGroup,
    MainNavLink,
    MenuEntry,
    MenuItem,
    SharedProps,
} from '@/types';

const icons = new Map<string, LucideIcon>();

/**
 * One icon component per name, made once, so React keeps the same component between renders.
 * A name lucide does not know shows a circle rather than nothing.
 */
function iconOf(name: string): LucideIcon {
    const made = icons.get(name);

    if (made !== undefined) {
        return made;
    }

    const known: IconName = (iconNames as readonly string[]).includes(name)
        ? (name as IconName)
        : 'circle';
    const icon = forwardRef<SVGSVGElement, LucideProps>((props, ref) => (
        <DynamicIcon {...props} name={known} ref={ref} />
    ));
    icon.displayName = `MenuIcon(${known})`;
    icons.set(name, icon);

    return icon;
}

/**
 * Whether an entry of the main menu is a group of pages rather than a page.
 */
export function isMainNavGroup(entry: MainNavEntry): entry is MainNavGroup {
    return 'items' in entry;
}

/**
 * Whether the page open now is a link's own page or one under it (`/invoices/{id}/edit` under
 * `/invoices`), so a group stays open and marked while the user works inside one of its pages.
 */
export function useHoldsCurrentPage(): (href: string) => boolean {
    const { currentUrl } = useCurrentUrl();

    return (href) => currentUrl === href || currentUrl.startsWith(`${href}/`);
}

/**
 * The main menu the server shares as `menu` (app/Http/Navigation.php), already narrowed to what
 * the user may open, as the kit's MainNavSidebar and MainNavHeader draw it: the titles and
 * descriptions translated, each icon a component, and a group's pages under it.
 */
export function useMainNavItems(): MainNavEntry[] {
    const { menu } = usePage<SharedProps>().props;
    const { t } = useTranslation();

    return useMemo(() => {
        const link = (item: MenuItem): MainNavLink => ({
            title: t(item.title),
            href: item.href,
            icon: iconOf(item.icon),
            ...(item.description === undefined
                ? {}
                : { description: t(item.description) }),
        });

        return menu.map((entry: MenuEntry): MainNavEntry =>
            'items' in entry
                ? {
                      title: t(entry.title),
                      icon: iconOf(entry.icon),
                      items: entry.items.map(link),
                  }
                : link(entry),
        );
    }, [menu, t]);
}
