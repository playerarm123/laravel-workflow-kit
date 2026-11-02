import { Panel } from '@xyflow/react';
import { ChevronDown, ChevronRight, Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import { styleOf } from '@/kit/node-styles';
import type { NodeStyle } from '@/kit/node-styles';
import { ShapeFrame } from '@/kit/structure-node';
import type { StructureNodeData } from '@/kit/types';
import { cn } from '@/lib/utils';

/**
 * What each shape on the canvas stands for, for the kinds this view holds. Where the view can leave
 * kinds out, each row shows or hides its kind, and the browser remembers which are hidden.
 */
export function Legend({
    nodes,
    hidden = [],
    onToggle,
    onShowAll,
}: {
    nodes: StructureNodeData[];
    hidden?: string[];
    onToggle?: (look: string) => void;
    onShowAll?: () => void;
}) {
    const [open, setOpen] = useState(true);
    const looks = [
        ...new Map(
            nodes.map((node) => {
                const look = styleOf(node);

                return [look.key, look] as [string, NodeStyle];
            }),
        ).values(),
    ];
    const anyHidden = looks.some((look) => hidden.includes(look.key));

    if (looks.length === 0) {
        return null;
    }

    return (
        <Panel position="top-left">
            <div className="rounded-md border bg-background/95 p-2 text-xs shadow-sm">
                <div className="flex items-center justify-between gap-3">
                    <button
                        type="button"
                        className="flex items-center gap-1 font-medium"
                        onClick={() => setOpen(!open)}
                    >
                        {open ? (
                            <ChevronDown className="size-3.5" />
                        ) : (
                            <ChevronRight className="size-3.5" />
                        )}
                        Legend
                    </button>
                    {open && anyHidden && onShowAll !== undefined && (
                        <button
                            type="button"
                            className="text-muted-foreground hover:text-foreground"
                            onClick={onShowAll}
                        >
                            Show all
                        </button>
                    )}
                </div>
                {open && (
                    <ul className="mt-2 space-y-0.5">
                        {looks.map((look) => {
                            const Icon = look.icon;
                            const off = hidden.includes(look.key);
                            const row = (
                                <>
                                    <ShapeFrame
                                        look={look}
                                        compact
                                        className="h-4 w-8 shrink-0"
                                    />
                                    <Icon
                                        className={`size-3.5 ${look.accent}`}
                                    />
                                    <span className="flex-1 whitespace-nowrap">
                                        {look.label}
                                    </span>
                                </>
                            );

                            return (
                                <li key={look.key}>
                                    {onToggle === undefined ? (
                                        <div className="flex items-center gap-2 py-0.5">
                                            {row}
                                        </div>
                                    ) : (
                                        <button
                                            type="button"
                                            title={off ? 'Show' : 'Hide'}
                                            aria-pressed={!off}
                                            className={cn(
                                                'group -mx-1 flex w-full items-center gap-2 rounded-sm px-1 py-0.5 text-left hover:bg-muted',
                                                off && 'opacity-50',
                                            )}
                                            onClick={() => onToggle(look.key)}
                                        >
                                            {row}
                                            {off ? (
                                                <EyeOff className="size-3.5 text-muted-foreground" />
                                            ) : (
                                                <Eye className="size-3.5 text-muted-foreground opacity-0 group-hover:opacity-100" />
                                            )}
                                        </button>
                                    )}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </Panel>
    );
}
