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

/** @see app/Http/Navigation.php — one entry of the main menu the server shares as `menu` */
export type MenuItem = {
    /** A translation key */
    title: string;
    href: string;
    /** A lucide icon name in kebab-case, as lucide.dev lists it */
    icon: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
