import type { LucideIcon } from 'lucide-react';
import type { ActionVariant } from '@/hooks/use-actions';
import { Button } from './ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from './ui/tooltip';

export type ItemButtonProps = {
    title: string;
    onClick: () => void;
    icon?: LucideIcon;
    iconOnly?: boolean;
    /** default `true` */
    visible?: boolean;
    /** default `'default'` (ghost) */
    variant?: ActionVariant;
    disabled?: boolean;
};

/** โทนของ action → variant ของ Button: default คือปุ่ม ghost ในแถว ไม่ใช่ปุ่ม primary */
const buttonVariant = {
    default: 'ghost',
    destructive: 'destructive',
} as const satisfies Record<ActionVariant, string>;

/**
 * ปุ่ม action ทั่วไป (ghost, มี icon + tooltip) — ไม่รู้จัก item ที่ปุ่มทำงานด้วย
 * ผู้เรียก bind item เข้า `onClick` เองตรงจุดที่มี item อยู่แล้ว (เช่น `RowButton` ในตาราง)
 *
 * แบบ icon-only ให้ตัวปุ่มเป็น TooltipTrigger ไม่ใช่ตัวไอคอน — `Button` ตั้ง
 * `[&_svg]:pointer-events-none` ไว้ svg จึงไม่ได้รับ hover และ focus ด้วยคีย์บอร์ดก็ตกที่ปุ่ม
 */
export function ItemButton({
    title,
    icon: Icon,
    iconOnly,
    visible = true,
    variant = 'default',
    disabled = false,
    onClick,
}: ItemButtonProps) {
    if (!visible) {
        return null;
    }

    if (iconOnly && Icon) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        variant={buttonVariant[variant]}
                        size="sm"
                        onClick={onClick}
                        disabled={disabled}
                        aria-label={title}
                    >
                        <Icon />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>{title}</TooltipContent>
            </Tooltip>
        );
    }

    return (
        <Button
            variant={buttonVariant[variant]}
            size="sm"
            onClick={onClick}
            disabled={disabled}
            aria-label={title}
        >
            {Icon && <Icon />}
            <span className="hidden sm:inline">{title}</span>
        </Button>
    );
}
