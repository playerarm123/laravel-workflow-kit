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
    /** แปลแล้วจากฝั่ง PHP — hook ที่รับอยู่นอก Inertia context จึงแปลเองไม่ได้ */
    title: string;
    message: string;
};

export type AuthLayoutProps = {
    children?: ReactNode;
    name?: string;
    title?: string;
    description?: string;
};
