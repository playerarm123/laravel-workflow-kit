import { Panel } from '@xyflow/react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { styleOf } from '@/kit/node-styles';
import type { NodeStyle } from '@/kit/node-styles';
import { ShapeFrame } from '@/kit/structure-node';
import type { StructureNodeData } from '@/kit/types';

/**
 * What each shape on the canvas stands for, for the kinds this view draws.
 */
export function Legend({ nodes }: { nodes: StructureNodeData[] }) {
    const [open, setOpen] = useState(true);
    const looks = [
        ...new Map(
            nodes.map((node) => {
                const look = styleOf(node);

                return [look.key, look] as [string, NodeStyle];
            }),
        ).values(),
    ];

    if (looks.length === 0) {
        return null;
    }

    return (
        <Panel position="top-left">
            <div className="rounded-md border bg-background/95 p-2 text-xs shadow-sm">
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
                {open && (
                    <ul className="mt-2 space-y-1.5">
                        {looks.map((look) => {
                            const Icon = look.icon;

                            return (
                                <li
                                    key={look.key}
                                    className="flex items-center gap-2"
                                >
                                    <ShapeFrame
                                        look={look}
                                        compact
                                        className="h-4 w-8 shrink-0"
                                    />
                                    <Icon
                                        className={`size-3.5 ${look.accent}`}
                                    />
                                    {look.label}
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </Panel>
    );
}
