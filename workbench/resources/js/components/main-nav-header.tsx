import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    NavigationMenuItem,
    navigationMenuTriggerStyle,
} from '@/components/ui/navigation-menu';
import {
    isMainNavGroup,
    useHoldsCurrentPage,
} from '@/hooks/use-main-nav-items';
import { cn } from '@/lib/utils';
import type { MainNavEntry, MainNavGroup, MainNavLink } from '@/types';

const activeItemStyles =
    'text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100';

function Underline() {
    return (
        <div className="absolute bottom-0 left-0 h-0.5 w-full translate-y-px bg-black dark:bg-white"></div>
    );
}

function HeaderLink({ item }: { item: MainNavLink }) {
    const opens = useHoldsCurrentPage();

    return (
        <NavigationMenuItem className="relative flex h-full items-center">
            <Link
                href={item.href}
                className={cn(
                    navigationMenuTriggerStyle(),
                    opens(item.href) && activeItemStyles,
                    'h-9 cursor-pointer px-3',
                )}
            >
                <item.icon className="mr-2 h-4 w-4" />
                {item.title}
            </Link>
            {opens(item.href) && <Underline />}
        </NavigationMenuItem>
    );
}

/**
 * A group: a button that drops its pages down, each with its description under its title, and
 * marked while it holds the page open now.
 */
function HeaderGroup({ group }: { group: MainNavGroup }) {
    const opens = useHoldsCurrentPage();
    const holdsCurrent = group.items.some((item) => opens(item.href));

    return (
        <NavigationMenuItem className="relative flex h-full items-center">
            <DropdownMenu>
                <DropdownMenuTrigger
                    className={cn(
                        navigationMenuTriggerStyle(),
                        holdsCurrent && activeItemStyles,
                        'h-9 cursor-pointer px-3',
                    )}
                >
                    <group.icon className="mr-2 h-4 w-4" />
                    {group.title}
                    <ChevronDown className="ml-1 h-3 w-3 opacity-60" />
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" className="min-w-64">
                    {group.items.map((item) => (
                        <DropdownMenuItem key={item.href} asChild>
                            <Link
                                href={item.href}
                                className={cn(
                                    'flex cursor-pointer items-start gap-3',
                                    opens(item.href) && 'bg-accent',
                                )}
                            >
                                <item.icon className="mt-0.5 h-4 w-4" />
                                <span className="flex flex-col">
                                    <span className="font-medium">
                                        {item.title}
                                    </span>
                                    {item.description !== undefined && (
                                        <span className="text-xs text-muted-foreground">
                                            {item.description}
                                        </span>
                                    )}
                                </span>
                            </Link>
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>
            {holdsCurrent && <Underline />}
        </NavigationMenuItem>
    );
}

/**
 * The main menu in the header (list-pages.md, "Menu"), inside the starter kit's
 * <NavigationMenuList>: a page as a link, a group as a dropdown of its pages. It takes what
 * useMainNavItems() returns, as app-header.tsx passes it.
 */
export function MainNavHeader({ items }: { items: MainNavEntry[] }) {
    return items.map((entry) =>
        isMainNavGroup(entry) ? (
            <HeaderGroup key={entry.title} group={entry} />
        ) : (
            <HeaderLink key={entry.href} item={entry} />
        ),
    );
}

/**
 * The main menu in the header's sheet on a small screen: a page as a link, a group as a heading
 * with its pages under it.
 */
export function MainNavMobile({ items }: { items: MainNavEntry[] }) {
    return items.map((entry) =>
        isMainNavGroup(entry) ? (
            <div key={entry.title} className="flex flex-col space-y-3">
                <span className="flex items-center space-x-2 font-medium text-muted-foreground">
                    <entry.icon className="h-5 w-5" />
                    <span>{entry.title}</span>
                </span>
                {entry.items.map((item) => (
                    <Link
                        key={item.href}
                        href={item.href}
                        className="flex items-center space-x-2 pl-7 font-medium"
                    >
                        <item.icon className="h-4 w-4" />
                        <span>{item.title}</span>
                    </Link>
                ))}
            </div>
        ) : (
            <Link
                key={entry.href}
                href={entry.href}
                className="flex items-center space-x-2 font-medium"
            >
                <entry.icon className="h-5 w-5" />
                <span>{entry.title}</span>
            </Link>
        ),
    );
}
