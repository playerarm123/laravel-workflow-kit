import type { LucideIcon } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

/**
 * A small action of the side panel: an icon that names itself in a tooltip and to a screen reader.
 */
export function IconAction({
    icon: Icon,
    label,
    tone = 'default',
    className,
    ...props
}: {
    icon: LucideIcon;
    label: string;
    tone?: 'default' | 'danger';
} & Omit<ComponentProps<typeof Button>, 'children' | 'size'>) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={label}
                    className={cn(
                        'size-7 text-muted-foreground hover:text-foreground',
                        tone === 'danger' &&
                            'hover:bg-destructive/10 hover:text-destructive',
                        className,
                    )}
                    {...props}
                >
                    <Icon className="size-3.5" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

/**
 * A titled part of the side panel, with its actions (an add, a sync) on the right of the title.
 */
export function PanelSection({
    title,
    actions,
    children,
}: {
    title: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="space-y-1.5">
            <div className="flex min-h-7 items-center justify-between gap-2">
                <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {title}
                </h3>
                {actions !== undefined && (
                    <div className="flex items-center">{actions}</div>
                )}
            </div>
            {children}
        </section>
    );
}

/**
 * The rows a card lists, as a small table: the columns named in `head`, then one row per entry.
 * An empty table says so in one line rather than drawing a header over nothing.
 */
export function PanelTable({
    head,
    empty,
    children,
}: {
    head: string[];
    empty: string;
    children: ReactNode[];
}) {
    if (children.length === 0) {
        return <p className="text-xs text-muted-foreground">{empty}</p>;
    }

    return (
        <div className="overflow-hidden rounded-md border">
            <Table className="text-xs">
                <TableHeader>
                    <TableRow className="bg-muted/50 hover:bg-muted/50">
                        {head.map((column, index) => (
                            <TableHead
                                key={`${column}:${index}`}
                                className="h-7 px-2 text-xs"
                            >
                                {column}
                            </TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>{children}</TableBody>
            </Table>
        </div>
    );
}

/**
 * One row of a panel table. The last cell holds the row's actions, kept to the right.
 */
export function PanelRow({
    cells,
    actions,
}: {
    cells: ReactNode[];
    actions?: ReactNode;
}) {
    return (
        <TableRow>
            {cells.map((cell, index) => (
                <TableCell
                    key={index}
                    className="px-2 py-1 align-top font-mono break-all whitespace-normal"
                >
                    {cell}
                </TableCell>
            ))}
            {actions !== undefined && (
                <TableCell className="w-px px-1 py-0.5 align-top">
                    <div className="flex items-center justify-end">
                        {actions}
                    </div>
                </TableCell>
            )}
        </TableRow>
    );
}
