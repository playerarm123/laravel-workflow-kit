import { Handle, Position } from '@xyflow/react';
import type { NodeProps } from '@xyflow/react';
import type { CSSProperties, ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { NODE_WIDTH, nodeHeight } from '@/kit/layout';
import type { StructureFlowNode } from '@/kit/layout';
import { styleOf } from '@/kit/node-styles';
import type { NodeStyle } from '@/kit/node-styles';
import type { StructureStatus } from '@/kit/types';
import { cn } from '@/lib/utils';

export { KIND_LABELS } from '@/kit/node-styles';

/**
 * Each status in the badge variants every shadcn project has, with a colour of its own for done and
 * differs, so the screen does not depend on the project's badge declaring more.
 */
const STATUS_BADGES: Record<
    StructureStatus,
    { variant: 'outline' | 'secondary'; className?: string }
> = {
    done: {
        variant: 'secondary',
        className:
            'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
    },
    ready: { variant: 'outline' },
    waiting: { variant: 'secondary' },
    differs: {
        variant: 'secondary',
        className:
            'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
    },
};

/**
 * A hexagon whose side tips reach `tip` pixels in.
 */
function hexagon(tip: number): string {
    return `polygon(${tip}px 0, calc(100% - ${tip}px) 0, 100% 50%, calc(100% - ${tip}px) 100%, ${tip}px 100%, 0 50%)`;
}

/**
 * A label tag whose left end comes to a point `tip` pixels in.
 */
function tag(tip: number): string {
    return `polygon(${tip}px 0, 100% 0, 100% 100%, ${tip}px 100%, 0 50%)`;
}

/**
 * A sheet whose top right corner is folded `fold` pixels down.
 */
function sheet(fold: number): string {
    return `polygon(0 0, calc(100% - ${fold}px) 0, 100% ${fold}px, 100% 100%, 0 100%)`;
}

export function StatusBadge({ status }: { status: StructureStatus }) {
    const { variant, className } = STATUS_BADGES[status];

    return (
        <Badge variant={variant} className={className}>
            {status}
        </Badge>
    );
}

/**
 * A shape drawn around its content. Shapes cut with clip-path lose their border, so those are two
 * layers: the outer one paints the outline, the inner one the fill.
 */
export function ShapeFrame({
    look,
    compact = false,
    className,
    style,
    children,
}: {
    look: NodeStyle;
    compact?: boolean;
    className?: string;
    style?: CSSProperties;
    children?: ReactNode;
}) {
    const padding = compact ? '' : 'px-3 py-2';

    switch (look.shape) {
        case 'hexagon':
        case 'tag':
        case 'sheet': {
            const clipPath = {
                hexagon: hexagon(compact ? 6 : 14),
                tag: tag(compact ? 6 : 14),
                sheet: sheet(compact ? 6 : 16),
            }[look.shape];

            return (
                <div
                    className={cn('relative p-[1.5px]', look.edge, className)}
                    style={{ ...style, clipPath }}
                >
                    <div
                        className={cn(
                            'h-full',
                            look.fill,
                            !compact &&
                                (look.shape === 'sheet'
                                    ? 'px-3 py-2'
                                    : 'px-6 py-2'),
                        )}
                        style={{ clipPath }}
                    >
                        {children}
                    </div>
                    {look.shape === 'sheet' && (
                        <div
                            className={cn(
                                'absolute top-0 right-0',
                                compact ? 'size-1.5' : 'size-4',
                                look.edge,
                            )}
                            style={{
                                clipPath: 'polygon(0 0, 0 100%, 100% 100%)',
                            }}
                        />
                    )}
                </div>
            );
        }
        case 'cylinder':
            return (
                <div
                    className={cn(
                        'relative rounded-[50%/14px] border',
                        look.border,
                        look.fill,
                        !compact && 'px-3 pt-6 pb-3',
                        className,
                    )}
                    style={style}
                >
                    <div
                        className={cn(
                            'absolute inset-x-0 top-0 rounded-[50%] border',
                            compact ? 'h-2' : 'h-7',
                            look.border,
                        )}
                    />
                    {children}
                </div>
            );
        default:
            return (
                <div
                    className={cn(
                        'border',
                        look.border,
                        look.fill,
                        !compact && look.shape !== 'headed' && padding,
                        compact
                            ? {
                                  box: 'rounded-sm',
                                  barred: 'rounded-sm border-l-4',
                                  pill: 'rounded-full',
                                  shield: 'rounded-t-sm rounded-b-[8px]',
                                  headed: 'rounded-sm border-t-4',
                                  double: 'rounded-sm border-[3px] border-double',
                                  dashed: 'rounded-sm border-dashed',
                                  rounded: 'rounded-md',
                              }[look.shape]
                            : {
                                  box: 'rounded-md',
                                  barred: 'rounded-md border-l-[6px]',
                                  pill: 'rounded-[28px] px-5',
                                  shield: 'rounded-t-md rounded-b-[28px] pb-6',
                                  headed: 'overflow-hidden rounded-md',
                                  double: 'rounded-md border-4 border-double',
                                  dashed: 'rounded-lg border-dashed',
                                  rounded: 'rounded-2xl',
                              }[look.shape],
                        look.shape === 'barred' && look.bar,
                        className,
                    )}
                    style={style}
                >
                    {children}
                </div>
            );
    }
}

/**
 * One piece of the structure as a card: shaped, coloured and marked by an icon for its kind, with
 * its name, what it holds and its status.
 */
export function StructureNode({
    data,
    selected,
}: NodeProps<StructureFlowNode>) {
    const look = styleOf(data);
    const Icon = look.icon;
    const header = (
        <div className="flex items-center justify-between gap-2">
            <span
                className={cn(
                    'flex items-center gap-1 text-[10px] font-medium tracking-wide uppercase',
                    look.accent,
                )}
            >
                <Icon className="size-3.5" />
                {look.label}
            </span>
            {data.status !== null && <StatusBadge status={data.status} />}
        </div>
    );
    const body = (
        <>
            <div className="truncate text-sm font-medium" title={data.label}>
                {data.label}
            </div>
            {data.items.length > 0 && (
                <ul className="mt-1 space-y-0.5 text-xs text-muted-foreground">
                    {data.items.map((item) => (
                        <li key={item} className="truncate" title={item}>
                            {item}
                        </li>
                    ))}
                </ul>
            )}
        </>
    );

    return (
        <div
            className={cn('relative', data.target !== null && 'cursor-pointer')}
            style={{
                width: NODE_WIDTH,
                filter: selected
                    ? 'drop-shadow(0 0 3px var(--ring)) drop-shadow(0 0 1px var(--ring))'
                    : undefined,
            }}
        >
            <Handle
                type="target"
                position={Position.Left}
                className="opacity-0"
            />
            <ShapeFrame
                look={look}
                className="text-card-foreground"
                style={{ minHeight: nodeHeight(data) }}
            >
                {look.shape === 'headed' ? (
                    <>
                        <div
                            className={cn(
                                'border-b px-3 py-1.5',
                                look.border,
                                look.band,
                            )}
                        >
                            {header}
                        </div>
                        <div className="px-3 py-2">{body}</div>
                    </>
                ) : (
                    <>
                        {header}
                        {body}
                    </>
                )}
            </ShapeFrame>
            <Handle
                type="source"
                position={Position.Right}
                className="opacity-0"
            />
        </div>
    );
}
