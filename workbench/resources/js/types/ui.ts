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

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
