import { Graph, layout } from '@dagrejs/dagre';
import { MarkerType } from '@xyflow/react';
import type { Edge, Node, XYPosition } from '@xyflow/react';
import { SHAPE_EXTRA_HEIGHT, styleOf } from '@/kit/node-styles';
import type {
    StructureEdgeData,
    StructureNodeData,
    StructureView,
} from '@/kit/types';

export const NODE_WIDTH = 240;

/** The narrowest a card may be resized to. */
export const MIN_WIDTH = 180;

export type CardSize = { width: number; height: number };

/**
 * What the developer did to one view's canvas, which the browser keeps per view: where they put
 * each card, the size they gave it, the cards folded to their header, and the cards whose children
 * are tucked away. `positions` stays empty until they first drag or resize a card there, and until
 * then the view is laid out for them.
 */
export type CanvasLayout = {
    positions: Record<string, XYPosition>;
    sizes: Record<string, CardSize>;
    folded: string[];
    collapsed: string[];
};

export const EMPTY_LAYOUT: CanvasLayout = {
    positions: {},
    sizes: {},
    folded: [],
    collapsed: [],
};

/**
 * A card as the canvas draws it: its piece, and how the developer left it.
 */
export type StructureCardData = StructureNodeData & {
    folded: boolean;
    /** Whether the developer gave the card its size */
    sized: boolean;
    /** How many cards collapsing it tucks away (0: it has none to tuck) */
    children: number;
    collapsed: boolean;
};

/**
 * The frame drawn around an aggregate and what it holds.
 */
export type FrameData = {
    label: string;
    head: string;
    members: string[];
};

export type StructureFlowNode = Node<StructureCardData, 'structure'>;

export type FrameFlowNode = Node<FrameData, 'frame'>;

export type CanvasNode = StructureFlowNode | FrameFlowNode;

/**
 * A line as the canvas draws it. A line that pointed into cards a collapse tucked away is drawn to
 * the card that tucked them, dashed.
 */
export type CanvasEdge = StructureEdgeData & { tucked: boolean };

/**
 * The height a node card takes: its header, one line per item unless it is folded, and the room
 * its shape needs.
 */
export function nodeHeight(data: StructureNodeData, folded = false): number {
    return (
        60 +
        (folded ? 0 : data.items.length * 18) +
        SHAPE_EXTRA_HEIGHT[styleOf(data).shape]
    );
}

/**
 * The size a card takes: the one the developer gave it, or its width and its content's height. A
 * folded card keeps its width and takes its header's height.
 */
export function cardSize(
    data: StructureNodeData,
    layout: CanvasLayout,
): CardSize {
    const given = layout.sizes[data.id];
    const folded = layout.folded.includes(data.id);

    return {
        width: given?.width ?? NODE_WIDTH,
        height:
            given !== undefined && !folded
                ? given.height
                : nodeHeight(data, folded),
    };
}

/**
 * A view without the cards of some looks (a look is a kind, or a kind's variant, as the legend
 * shows it): their cards, the lines that touch them, and the cards of elsewhere only they pointed
 * at.
 */
export function withoutLooks(
    view: StructureView,
    looks: string[],
): StructureView {
    const hidden = new Set(
        view.nodes
            .filter((node) => looks.includes(styleOf(node).key))
            .map((node) => node.id),
    );
    const edges = view.edges.filter(
        (edge) => !hidden.has(edge.source) && !hidden.has(edge.target),
    );
    const linked = new Set(edges.flatMap((edge) => [edge.source, edge.target]));

    return {
        nodes: view.nodes.filter(
            (node) =>
                !hidden.has(node.id) &&
                (node.kind !== 'external' || linked.has(node.id)),
        ),
        edges,
    };
}

/**
 * The cards collapsing a card tucks away. An aggregate's are the cards of its group; any other
 * card's are those it points at that nothing else still drawn points at, and theirs in turn.
 */
export function childrenOf(view: StructureView, id: string): string[] {
    const head = view.nodes.find((node) => node.id === id);

    if (head === undefined) {
        return [];
    }

    if (head.group === head.id) {
        return view.nodes
            .filter((node) => node.group === id && node.id !== id)
            .map((node) => node.id);
    }

    const incoming = new Map<string, string[]>();

    for (const edge of view.edges) {
        incoming.set(edge.target, [
            ...(incoming.get(edge.target) ?? []),
            edge.source,
        ]);
    }

    const tucked = new Set<string>();
    let grew = true;

    while (grew) {
        grew = false;

        for (const node of view.nodes) {
            if (node.id === id || tucked.has(node.id)) {
                continue;
            }

            const from = incoming.get(node.id) ?? [];

            if (
                from.length > 0 &&
                from.some((source) => source === id || tucked.has(source)) &&
                from.every((source) => source === id || tucked.has(source))
            ) {
                tucked.add(node.id);
                grew = true;
            }
        }
    }

    return [...tucked];
}

/**
 * A view with the children of its collapsed cards tucked away. Each line that touched a tucked
 * card is drawn to the card that tucked it instead, once, so what it was tied to still shows.
 * `tucked` counts what each collapsed card holds away.
 */
export function withoutCollapsed(
    view: StructureView,
    collapsed: string[],
): {
    nodes: StructureNodeData[];
    edges: CanvasEdge[];
    tucked: Map<string, number>;
} {
    const holder = new Map<string, string>();
    const tucked = new Map<string, number>();

    for (const id of collapsed) {
        if (holder.has(id) || !view.nodes.some((node) => node.id === id)) {
            continue;
        }

        const children = childrenOf(view, id);

        for (const child of children) {
            holder.set(child, id);
        }

        tucked.set(id, children.length);
    }

    // A collapsed card inside another's collapse hands what it holds up to that card.
    const shown = (id: string): string => {
        let at = id;

        while (holder.has(at)) {
            at = holder.get(at) ?? at;
        }

        return at;
    };

    const edges = new Map<string, CanvasEdge>();

    for (const edge of view.edges) {
        const source = shown(edge.source);
        const target = shown(edge.target);

        if (source === target) {
            continue;
        }

        const moved = source !== edge.source || target !== edge.target;
        const id = moved ? `${source}->${target}:tucked` : edge.id;
        const before = edges.get(id);

        edges.set(id, {
            id,
            source,
            target,
            label:
                before === undefined || before.label === edge.label
                    ? edge.label
                    : null,
            tucked: moved,
        });
    }

    return {
        nodes: view.nodes.filter((node) => !holder.has(node.id)),
        edges: [...edges.values()],
        tucked: new Map([...tucked].filter(([id]) => !holder.has(id))),
    };
}

/**
 * Where each card of a view was last drawn, by the view's key. A change to the manifest draws the
 * view again, and a card that was already there stays where the developer last saw it.
 */
const PLACED = new Map<string, Map<string, XYPosition>>();

const GAP = 24;

/** The room a frame leaves around its cards, and above them for its title. */
const FRAME_PAD = 16;
const FRAME_TITLE = 28;

/**
 * Forgets where the cards of a view were drawn, under every key that starts with the view's, so it
 * is laid out afresh.
 */
export function forgetPlaced(view: string): void {
    for (const key of [...PLACED.keys()]) {
        if (key.startsWith(view)) {
            PLACED.delete(key);
        }
    }
}

/**
 * Lays a view out left to right with dagre, so what calls sits left of what it calls, an
 * aggregate's group kept together. A card the developer placed, or that was drawn before under the
 * same key, keeps its place: only a new card takes dagre's. No two cards overlap: the cards in hand
 * (being dragged) claim their room first, then the others top to bottom, and a card that would
 * overlap one already placed moves down below it.
 */
export function layoutView(
    view: StructureView,
    key: string,
    canvas: CanvasLayout,
    inHand: ReadonlySet<string> = new Set(),
): {
    nodes: CanvasNode[];
    edges: Edge[];
    placed: Record<string, XYPosition>;
} {
    const { nodes, edges, tucked } = withoutCollapsed(view, canvas.collapsed);
    const shown = { nodes, edges };
    const sizes = new Map(
        nodes.map((node) => [node.id, cardSize(node, canvas)]),
    );
    const size = (id: string): CardSize =>
        sizes.get(id) ?? { width: NODE_WIDTH, height: 0 };
    const framed = frames(shown, tucked);
    const graph = new Graph<
        object,
        { width: number; height: number; x?: number; y?: number }
    >({ compound: framed.length > 0 });

    graph.setGraph({ rankdir: 'LR', nodesep: GAP, ranksep: 96 });
    graph.setDefaultEdgeLabel(() => ({}));

    for (const frame of framed) {
        graph.setNode(frameId(frame.head), { width: 0, height: 0 });
    }

    for (const node of nodes) {
        // dagre writes where it puts a node into the label it is given, so it gets a copy.
        graph.setNode(node.id, { ...size(node.id) });
    }

    for (const frame of framed) {
        for (const member of frame.members) {
            graph.setParent(member, frameId(frame.head));
        }
    }

    for (const edge of edges) {
        graph.setEdge(edge.source, edge.target);
    }

    layout(graph);

    const before = new Map([
        ...(PLACED.get(key) ?? []),
        ...Object.entries(canvas.positions),
    ]);
    const placed = new Map<string, XYPosition>();
    const boxes: (XYPosition & CardSize)[] = [];
    const kept = nodes.map((node) => {
        const fresh = graph.node(node.id);
        const { width, height } = size(node.id);

        return {
            node,
            rank: inHand.has(node.id) ? 0 : before.has(node.id) ? 1 : 2,
            position: before.get(node.id) ?? {
                x: (fresh.x ?? 0) - width / 2,
                y: (fresh.y ?? 0) - height / 2,
            },
        };
    });

    kept.sort((a, b) => a.rank - b.rank || a.position.y - b.position.y);

    for (const entry of kept) {
        const { width, height } = size(entry.node.id);
        let { x, y } = entry.position;

        if (entry.rank > 0) {
            for (const box of [...boxes].sort((a, b) => a.y - b.y)) {
                const sideBySide = x < box.x + box.width && box.x < x + width;
                const overlaps =
                    y < box.y + box.height + GAP && box.y < y + height + GAP;

                if (sideBySide && overlaps) {
                    y = box.y + box.height + GAP;
                }
            }
        }

        x = Math.round(x);
        y = Math.round(y);
        boxes.push({ x, y, width, height });
        placed.set(entry.node.id, { x, y });
    }

    // Cards tucked away or filtered out keep their place for when they come back.
    PLACED.set(key, new Map([...before, ...placed]));

    return {
        nodes: [
            ...framed.map((frame): FrameFlowNode => {
                const members = frame.members.map((id) => ({
                    ...size(id),
                    ...(placed.get(id) ?? { x: 0, y: 0 }),
                }));
                const left = Math.min(...members.map((box) => box.x));
                const top = Math.min(...members.map((box) => box.y));
                const right = Math.max(
                    ...members.map((box) => box.x + box.width),
                );
                const bottom = Math.max(
                    ...members.map((box) => box.y + box.height),
                );

                return {
                    id: frameId(frame.head),
                    type: 'frame',
                    data: {
                        label: frame.label,
                        head: frame.head,
                        members: frame.members,
                    },
                    position: {
                        x: left - FRAME_PAD,
                        y: top - FRAME_PAD - FRAME_TITLE,
                    },
                    width: right - left + FRAME_PAD * 2,
                    height: bottom - top + FRAME_PAD * 2 + FRAME_TITLE,
                    zIndex: -1,
                    selectable: false,
                    dragHandle: '.frame-handle',
                    style: { pointerEvents: 'none' },
                };
            }),
            ...nodes.map((node): StructureFlowNode => ({
                id: node.id,
                type: 'structure',
                data: {
                    ...node,
                    folded: canvas.folded.includes(node.id),
                    sized: node.id in canvas.sizes,
                    children:
                        tucked.get(node.id) ??
                        childrenOf(shown, node.id).length,
                    collapsed: tucked.has(node.id),
                },
                ...size(node.id),
                position: placed.get(node.id) ?? { x: 0, y: 0 },
            })),
        ],
        edges: edges.map((edge) => ({
            id: edge.id,
            source: edge.source,
            target: edge.target,
            label: edge.label ?? undefined,
            markerEnd: { type: MarkerType.ArrowClosed },
            style: edge.tucked ? { strokeDasharray: '4 4' } : undefined,
        })),
        placed: Object.fromEntries(placed),
    };
}

export function frameId(head: string): string {
    return `frame:${head}`;
}

/**
 * The frames a view draws: one per aggregate whose group shows more than the aggregate itself.
 */
function frames(
    view: { nodes: StructureNodeData[] },
    tucked: Map<string, number>,
): { head: string; label: string; members: string[] }[] {
    return view.nodes
        .filter((node) => node.group === node.id && !tucked.has(node.id))
        .map((head) => ({
            head: head.id,
            label: head.label,
            members: view.nodes
                .filter((node) => node.group === head.id)
                .map((node) => node.id),
        }))
        .filter((frame) => frame.members.length > 1);
}

/**
 * The cards and lines a focused card is tied to: itself, the cards one line away, and those lines.
 * With nothing focused, everything is.
 */
export function focusOf(
    edges: Edge[],
    id: string | null,
): { nodes: Set<string>; edges: Set<string> } | null {
    if (id === null) {
        return null;
    }

    const touching = edges.filter(
        (edge) => edge.source === id || edge.target === id,
    );

    return {
        nodes: new Set([
            id,
            ...touching.flatMap((edge) => [edge.source, edge.target]),
        ]),
        edges: new Set(touching.map((edge) => edge.id)),
    };
}
