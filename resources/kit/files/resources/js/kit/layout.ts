import { Graph, layout } from '@dagrejs/dagre';
import { MarkerType } from '@xyflow/react';
import type { Edge, Node } from '@xyflow/react';
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
 * Lays a view out left to right with dagre, so what calls sits left of what it calls.
 */
export function layoutView(view: StructureView): {
    nodes: StructureFlowNode[];
    edges: Edge[];
} {
    const graph = new Graph<
        object,
        { width: number; height: number; x?: number; y?: number }
    >();

    graph.setGraph({ rankdir: 'LR', nodesep: 24, ranksep: 96 });
    graph.setDefaultEdgeLabel(() => ({}));

    for (const node of view.nodes) {
        graph.setNode(node.id, { width: NODE_WIDTH, height: nodeHeight(node) });
    }

    for (const edge of view.edges) {
        graph.setEdge(edge.source, edge.target);
    }

    layout(graph);

    return {
        nodes: view.nodes.map((node) => {
            const placed = graph.node(node.id);

            return {
                id: node.id,
                type: 'structure',
                data: node,
                width: NODE_WIDTH,
                height: nodeHeight(node),
                position: {
                    x: (placed.x ?? 0) - NODE_WIDTH / 2,
                    y: (placed.y ?? 0) - nodeHeight(node) / 2,
                },
            };
        }),
        edges: view.edges.map((edge) => ({
            id: edge.id,
            source: edge.source,
            target: edge.target,
            label: edge.label ?? undefined,
            markerEnd: { type: MarkerType.ArrowClosed },
        })),
    };
}
