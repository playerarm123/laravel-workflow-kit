import { Graph, layout } from '@dagrejs/dagre';
import { MarkerType } from '@xyflow/react';
import type { Edge, Node, XYPosition } from '@xyflow/react';
import { SHAPE_EXTRA_HEIGHT, styleOf } from '@/kit/node-styles';
import type {
    StructureNodeData,
    StructureNodeKind,
    StructureView,
} from '@/kit/types';

export const NODE_WIDTH = 240;

export type StructureFlowNode = Node<StructureNodeData, 'structure'>;

/**
 * The height a node card takes: its header, one line per item, and the room its shape needs.
 */
export function nodeHeight(data: StructureNodeData): number {
    return (
        60 + data.items.length * 18 + SHAPE_EXTRA_HEIGHT[styleOf(data).shape]
    );
}

/**
 * A view without the cards of some kinds: their cards, the lines that touch them, and the cards of
 * elsewhere only they pointed at.
 */
export function withoutKinds(
    view: StructureView,
    kinds: StructureNodeKind[],
): StructureView {
    const hidden = new Set(
        view.nodes
            .filter((node) => kinds.includes(node.kind))
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
 * Where each card of a view was last drawn, by the view's key. A change to the manifest draws the
 * view again, and a card that was already there stays where the developer last saw it.
 */
const PLACED = new Map<string, Map<string, XYPosition>>();

const GAP = 24;

/**
 * Lays a view out left to right with dagre, so what calls sits left of what it calls. A view drawn
 * before under the same key keeps its cards where they were: only a new card takes dagre's place,
 * and a card that would overlap another moves down below it.
 */
export function layoutView(
    view: StructureView,
    key: string,
): {
    nodes: StructureFlowNode[];
    edges: Edge[];
} {
    const graph = new Graph<
        object,
        { width: number; height: number; x?: number; y?: number }
    >();

    graph.setGraph({ rankdir: 'LR', nodesep: GAP, ranksep: 96 });
    graph.setDefaultEdgeLabel(() => ({}));

    for (const node of view.nodes) {
        graph.setNode(node.id, { width: NODE_WIDTH, height: nodeHeight(node) });
    }

    for (const edge of view.edges) {
        graph.setEdge(edge.source, edge.target);
    }

    layout(graph);

    const before = PLACED.get(key);
    const placed = new Map<string, XYPosition>();
    const boxes: { x: number; y: number; height: number }[] = [];
    const kept = view.nodes.map((node) => {
        const fresh = graph.node(node.id);

        return {
            node,
            old: before?.has(node.id) === true,
            position: before?.get(node.id) ?? {
                x: (fresh.x ?? 0) - NODE_WIDTH / 2,
                y: (fresh.y ?? 0) - nodeHeight(node) / 2,
            },
        };
    });

    // The cards already drawn claim their room first, top to bottom, then the new ones.
    kept.sort(
        (a, b) => Number(b.old) - Number(a.old) || a.position.y - b.position.y,
    );

    for (const entry of kept) {
        const height = nodeHeight(entry.node);
        let { x, y } = entry.position;

        for (const box of [...boxes].sort((a, b) => a.y - b.y)) {
            const sideBySide = Math.abs(box.x - x) < NODE_WIDTH;
            const overlaps =
                y < box.y + box.height + GAP && box.y < y + height + GAP;

            if (sideBySide && overlaps) {
                y = box.y + box.height + GAP;
            }
        }

        x = Math.round(x);
        y = Math.round(y);
        boxes.push({ x, y, height });
        placed.set(entry.node.id, { x, y });
    }

    PLACED.set(key, placed);

    return {
        nodes: view.nodes.map((node) => ({
            id: node.id,
            type: 'structure',
            data: node,
            width: NODE_WIDTH,
            height: nodeHeight(node),
            position: placed.get(node.id) ?? { x: 0, y: 0 },
        })),
        edges: view.edges.map((edge) => ({
            id: edge.id,
            source: edge.source,
            target: edge.target,
            label: edge.label ?? undefined,
            markerEnd: { type: MarkerType.ArrowClosed },
        })),
    };
}
