import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import {
    isMainNavGroup,
    useHoldsCurrentPage,
} from '@/hooks/use-main-nav-items';
import type { MainNavEntry, MainNavGroup, MainNavLink } from '@/types';

function SidebarLink({ item }: { item: MainNavLink }) {
    const opens = useHoldsCurrentPage();

    return (
        <SidebarMenuItem>
            <SidebarMenuButton
                asChild
                isActive={opens(item.href)}
                tooltip={{ children: item.title }}
            >
                <Link href={item.href} prefetch>
                    <item.icon />
                    <span>{item.title}</span>
                </Link>
            </SidebarMenuButton>
        </SidebarMenuItem>
    );
}

/**
 * A group: a heading that folds its pages away, open while it holds the page open now. With the
 * sidebar folded to its icons the pages have no room under the heading, so they open beside it.
 */
function SidebarGroupEntry({ group }: { group: MainNavGroup }) {
    const opens = useHoldsCurrentPage();
    const { state, isMobile } = useSidebar();
    const holdsCurrent = group.items.some((item) => opens(item.href));

    if (state === 'collapsed' && !isMobile) {
        return (
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            isActive={holdsCurrent}
                            tooltip={{ children: group.title }}
                        >
                            <group.icon />
                            <span>{group.title}</span>
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side="right" align="start">
                        <DropdownMenuLabel>{group.title}</DropdownMenuLabel>
                        {group.items.map((item) => (
                            <DropdownMenuItem key={item.href} asChild>
                                <Link href={item.href} prefetch>
                                    <item.icon />
                                    <span>{item.title}</span>
                                </Link>
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        );
    }

    return (
        <Collapsible
            asChild
            defaultOpen={holdsCurrent}
            className="group/collapsible"
        >
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton
                        isActive={holdsCurrent}
                        tooltip={{ children: group.title }}
                    >
                        <group.icon />
                        <span>{group.title}</span>
                        <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub>
                        {group.items.map((item) => (
                            <SidebarMenuSubItem key={item.href}>
                                <SidebarMenuSubButton
                                    asChild
                                    isActive={opens(item.href)}
                                >
                                    <Link href={item.href} prefetch>
                                        <item.icon />
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}

/**
 * The main menu in the sidebar (list-pages.md, "Menu"): a page as a link, a group as a heading
 * over its pages. It takes what useMainNavItems() returns, as app-sidebar.tsx passes it.
 */
export function MainNavSidebar({ items }: { items: MainNavEntry[] }) {
    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarMenu>
                {items.map((entry) =>
                    isMainNavGroup(entry) ? (
                        <SidebarGroupEntry key={entry.title} group={entry} />
                    ) : (
                        <SidebarLink key={entry.href} item={entry} />
                    ),
                )}
            </SidebarMenu>
        </SidebarGroup>
    );
}
