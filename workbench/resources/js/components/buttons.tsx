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

/** An action's tone → the Button variant: default is a ghost button in a row, not a primary one */
const buttonVariant = {
    default: 'ghost',
    destructive: 'destructive',
} as const satisfies Record<ActionVariant, string>;

/**
 * A generic action button (ghost, with icon + tooltip). It does not know the item it acts on.
 * The caller binds the item into `onClick` where the item already is (e.g. `RowButton` in the table).
 *
 * When icon-only, the button itself is the TooltipTrigger, not the icon. `Button` sets
 * `[&_svg]:pointer-events-none`, so the svg gets no hover, and keyboard focus lands on the button anyway.
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
