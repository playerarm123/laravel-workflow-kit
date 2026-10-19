import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import type { BreadcrumbItem } from '@/types/navigation';

export type AppLayoutProps = {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
};

export type AppVariant = 'header' | 'sidebar';

/** @see app/Http/FlashToast.php */
export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    /** Translated in PHP: the hook that reads it sits outside the Inertia context, so it cannot translate. */
    title: string;
    message: string;
};

/** @see app/Http/Navigation.php — one page of the main menu the server shares as `menu` */
export type MenuItem = {
    /** A translation key */
    title: string;
    href: string;
    /** A lucide icon name in kebab-case, as lucide.dev lists it */
    icon: string;
    /** A translation key: the second line the header's dropdown shows under the title */
    description?: string;
};

/** @see app/Http/Navigation.php — a heading over some pages; it never holds another group */
export type MenuGroup = {
    /** A translation key */
    title: string;
    /** A lucide icon name in kebab-case, as lucide.dev lists it */
    icon: string;
    /** Never empty: the server leaves out a group the user may open none of */
    items: MenuItem[];
};

/** @see app/Http/Navigation.php — one top-level entry of the main menu */
export type MenuEntry = MenuItem | MenuGroup;

/** A page of the main menu as the sidebar and the header draw it: translated, its icon a component */
export type MainNavLink = {
    title: string;
    href: string;
    icon: LucideIcon;
    description?: string;
};

/** A group of the main menu as the sidebar and the header draw it */
export type MainNavGroup = {
    title: string;
    icon: LucideIcon;
    items: MainNavLink[];
};

export type MainNavEntry = MainNavLink | MainNavGroup;

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
