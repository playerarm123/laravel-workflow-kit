import { Handle, NodeResizer, Position } from '@xyflow/react';
import type { NodeProps } from '@xyflow/react';
import {
    Boxes,
    ChevronDown,
    ChevronRight,
    ChevronsDownUp,
    Minus,
} from 'lucide-react';
import type { CSSProperties, MouseEvent, ReactNode } from 'react';
import { createContext, useContext } from 'react';
import { Badge } from '@/components/ui/badge';
import { MIN_WIDTH, NODE_WIDTH, nodeHeight } from '@/kit/layout';
import type { FrameFlowNode, StructureFlowNode } from '@/kit/layout';
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
 * What a card's own buttons do, which the screen hands down: fold it to its header, tuck away the
 * cards it leads to, and keep the size it was resized to.
 */
export type CardActions = {
    fold: (id: string) => void;
    collapse: (id: string) => void;
    resized: () => void;
};

export const CardActionsContext = createContext<CardActions>({
    fold: () => undefined,
    collapse: () => undefined,
    resized: () => undefined,
});

/**
 * A button on a card that does its own thing: the click neither selects the card nor drags it, and
 * a double click does not open the card's view.
 */
function CardButton({
    label,
    className,
    onClick,
    children,
}: {
    label: string;
    className?: string;
    onClick: () => void;
    children: ReactNode;
}) {
    const own = (event: MouseEvent) => event.stopPropagation();

    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            className={cn('nodrag nopan', className)}
            onClick={(event) => {
                own(event);
                onClick();
            }}
            onDoubleClick={own}
        >
            {children}
        </button>
    );
}

/**
 * One piece of the structure as a card: shaped, coloured and marked by an icon for its kind, with
 * its name, what it holds and its status. The chevron folds it to its header; selected, it can be
 * resized, and a card that leads to cards nothing else does can tuck them away (the button shows
 * while the card is pointed at or selected, and always once they are tucked).
 */
export function StructureNode({
    id,
    data,
    selected,
    width,
    height,
}: NodeProps<StructureFlowNode>) {
    const actions = useContext(CardActionsContext);
    const look = styleOf(data);
    const Icon = look.icon;
    const Fold = data.folded ? ChevronRight : ChevronDown;
    const header = (
        <div className="flex shrink-0 items-center justify-between gap-2">
            <span
                className={cn(
                    'flex min-w-0 items-center gap-1 text-[10px] font-medium tracking-wide uppercase',
                    look.accent,
                )}
            >
                {data.items.length > 0 && (
                    <CardButton
                        label={data.folded ? 'Unfold' : 'Fold'}
                        className="-ml-1 rounded-sm opacity-60 hover:bg-foreground/10 hover:opacity-100"
                        onClick={() => actions.fold(id)}
                    >
                        <Fold className="size-3.5" />
                    </CardButton>
                )}
                <Icon className="size-3.5 shrink-0" />
                <span className="truncate">{look.label}</span>
            </span>
            {data.status !== null && <StatusBadge status={data.status} />}
        </div>
    );
    const body = (
        <>
            <div
                className="shrink-0 truncate text-sm font-medium"
                title={data.label}
            >
                {data.label}
            </div>
            {!data.folded && data.items.length > 0 && (
                <ul
                    className={cn(
                        'mt-1 space-y-0.5 text-xs text-muted-foreground',
                        data.sized && 'nowheel min-h-0 flex-1 overflow-y-auto',
                    )}
                >
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
            className={cn(
                'group relative',
                data.target !== null && 'cursor-pointer',
            )}
            style={{
                width: width ?? NODE_WIDTH,
                height: data.sized ? height : undefined,
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
                className={cn('text-card-foreground', data.sized && 'h-full')}
                style={
                    data.sized
                        ? undefined
                        : { minHeight: nodeHeight(data, data.folded) }
                }
            >
                <div
                    className={cn(data.sized && 'flex h-full min-h-0 flex-col')}
                >
                    {look.shape === 'headed' ? (
                        <>
                            <div
                                className={cn(
                                    'shrink-0 border-b px-3 py-1.5',
                                    look.border,
                                    look.band,
                                )}
                            >
                                {header}
                            </div>
                            <div
                                className={cn(
                                    'px-3 py-2',
                                    data.sized &&
                                        'flex min-h-0 flex-1 flex-col',
                                )}
                            >
                                {body}
                            </div>
                        </>
                    ) : (
                        <>
                            {header}
                            {body}
                        </>
                    )}
                </div>
            </ShapeFrame>
            <Handle
                type="source"
                position={Position.Right}
                className="opacity-0"
            />
            <NodeResizer
                isVisible={selected}
                minWidth={MIN_WIDTH}
                minHeight={nodeHeight(data, true)}
                color="var(--ring)"
                onResizeEnd={() => actions.resized()}
            />
            {data.children > 0 && (
                <CardButton
                    label={
                        data.collapsed
                            ? `Show the ${data.children} tucked away`
                            : `Tuck away the ${data.children} only it leads to`
                    }
                    className={cn(
                        'absolute -bottom-2.5 left-1/2 flex h-5 min-w-5 -translate-x-1/2 items-center justify-center rounded-full border bg-background px-1 text-[10px] font-medium text-muted-foreground shadow-sm hover:text-foreground',
                        !data.collapsed &&
                            !selected &&
                            'opacity-0 group-hover:opacity-100',
                    )}
                    onClick={() => actions.collapse(id)}
                >
                    {data.collapsed ? (
                        `+${data.children}`
                    ) : (
                        <Minus className="size-3" />
                    )}
                </CardButton>
            )}
        </div>
    );
}

/**
 * The frame around an aggregate and what it holds. Its title drags the whole group and collapses
 * it to the aggregate's card; the rest of it lets clicks through to the canvas.
 */
export function FrameNode({ data, width, height }: NodeProps<FrameFlowNode>) {
    const actions = useContext(CardActionsContext);
    const look = styleOf({ kind: 'aggregate', variant: null });

    return (
        <div
            className={cn(
                'rounded-xl border border-dashed bg-amber-400/5',
                look.border,
            )}
            style={{ width, height }}
        >
            <div
                className={cn(
                    'frame-handle pointer-events-auto flex w-fit cursor-grab items-center gap-1.5 px-3 py-1.5 text-xs font-medium',
                    look.accent,
                )}
            >
                <Boxes className="size-3.5" />
                {data.label}
                <span className="font-normal text-muted-foreground">
                    {data.members.length}
                </span>
                <CardButton
                    label={`Collapse ${data.label}`}
                    className="rounded-sm p-0.5 text-muted-foreground hover:bg-foreground/10 hover:text-foreground"
                    onClick={() => actions.collapse(data.head)}
                >
                    <ChevronsDownUp className="size-3.5" />
                </CardButton>
            </div>
        </div>
    );
}
