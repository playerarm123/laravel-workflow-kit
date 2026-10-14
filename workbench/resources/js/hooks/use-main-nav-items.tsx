import { usePage } from '@inertiajs/react';
import type { LucideIcon, LucideProps } from 'lucide-react';
import { DynamicIcon, iconNames } from 'lucide-react/dynamic';
import type { IconName } from 'lucide-react/dynamic';
import { forwardRef, useMemo } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import type { NavItem, SharedProps } from '@/types';

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
        <DynamicIcon name={known} ref={ref} {...props} />
    ));
    icon.displayName = `MenuIcon(${known})`;
    icons.set(name, icon);

    return icon;
}

/**
 * The main menu the server shares as `menu` (app/Http/Navigation.php), already narrowed to what
 * the user may open, as the starter kit's sidebar and header draw it: the titles translated and
 * each icon a component.
 */
export function useMainNavItems(): NavItem[] {
    const { menu } = usePage<SharedProps>().props;
    const { t } = useTranslation();

    return useMemo(
        () =>
            menu.map((item) => ({
                title: t(item.title),
                href: item.href,
                icon: iconOf(item.icon),
            })),
        [menu, t],
    );
}
