import { useHttp } from '@inertiajs/react';
import {
    Background,
    ControlButton,
    Controls,
    MiniMap,
    ReactFlow,
    useReactFlow,
} from '@xyflow/react';
import type { NodeChange, XYPosition } from '@xyflow/react';
import {
    ArrowUpRight,
    Copy,
    FoldVertical,
    Pencil,
    Replace,
    RotateCcw,
    Settings2,
    UnfoldVertical,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    useEffect,
    useMemo,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import { toast } from 'sonner';
import { CommandDialog } from '@/kit/command-runner';
import type { CommandRequest } from '@/kit/command-runner';
import { ContextForm } from '@/kit/context-form';
import { ExceptionForm } from '@/kit/exception-form';
import {
    EMPTY_LAYOUT,
    focusOf,
    forgetPlaced,
    layoutView,
    NODE_WIDTH,
    withoutLooks,
} from '@/kit/layout';
import type { CanvasLayout, CanvasNode, CardSize } from '@/kit/layout';
import { Legend } from '@/kit/legend';
import { MethodForm } from '@/kit/method-form';
import { styleOf } from '@/kit/node-styles';
import {
    AggregateChildren,
    ByHand,
    ChildOf,
    ControllerMethods,
    endpointFor,
    EntityMethods,
    EntityState,
    RemoveButton,
    SyncButton,
} from '@/kit/panel-sections';
import type { Changed, Editing } from '@/kit/panel-sections';
import {
    IconAction,
    PanelRow,
    PanelSection,
    PanelTable,
} from '@/kit/panel-table';
import { PieceForm } from '@/kit/piece-form';
import { CancelReplacementButton, ReplaceForm } from '@/kit/replace-form';
import {
    NewResourceForm,
    ResourceMenuForm,
    ResourceSettingsForm,
} from '@/kit/resource-form';
import { ResourcePieceForm } from '@/kit/resource-piece-form';
import { StateForm } from '@/kit/state-form';
import {
    CardActionsContext,
    FrameNode,
    KIND_LABELS,
    StatusBadge,
    StructureNode,
} from '@/kit/structure-node';
import type { CardActions } from '@/kit/structure-node';
import { StructureToolbar } from '@/kit/structure-toolbar';
import type {
    ReplaceableSection,
    ResourceSection,
    StructureEndpoints,
    StructureGraph,
    StructureNodeData,
    StructureNodeKind,
    StructurePayload,
    StructureSection,
    StructureTarget,
    StructureView,
} from '@/kit/types';
import { VocabularyForm } from '@/kit/vocabulary-form';

const NODE_TYPES = { structure: StructureNode, frame: FrameNode };

const NOTHING_IN_HAND: ReadonlySet<string> = new Set();

const NO_LOOKS: string[] = [];

/** React Flow fills the icons of its control buttons, which outlines lucide's. */
const STROKED = { fill: 'none' };

/**
 * The looks a context view leaves out until the developer shows them: its vocabulary, its entities
 * and its exceptions, which a context of any size draws many of.
 */
const HIDDEN_IN_CONTEXT = [
    'enum',
    'status',
    'valueObject',
    'entity',
    'exception',
];

const VOCABULARY = ['enum', 'status', 'valueObject'];

const FIT_VIEW = { minZoom: 0.5, maxZoom: 1 };

const CONTEXT_SECTIONS: Partial<Record<StructureNodeKind, StructureSection>> = {
    aggregate: 'aggregates',
    service: 'services',
    port: 'ports',
    useCase: 'useCases',
    enum: 'enums',
    valueObject: 'valueObjects',
    exception: 'exceptions',
};

/**
 * The shared kernel, which holds only enums, value objects and invalid values, so its view always
 * shows them.
 */
const SHARED = 'Shared';

const RESOURCE_SECTIONS: Partial<Record<StructureNodeKind, ResourceSection>> = {
    action: 'actions',
    page: 'pages',
};

function subscribeToHash(onChange: () => void): () => void {
    window.addEventListener('hashchange', onChange);

    return () => window.removeEventListener('hashchange', onChange);
}

/**
 * The view the url's hash names (`#context/Agency`, `#resource/Agent`), or the overview.
 */
function placeOf(hash: string): StructureTarget | null {
    const [view, name] = hash.replace(/^#/, '').split('/');

    return (view === 'context' || view === 'resource') && name
        ? { view, name: decodeURIComponent(name) }
        : null;
}

function viewOf(
    graph: StructureGraph,
    place: StructureTarget | null,
): StructureView {
    if (place === null) {
        return graph.overview;
    }

    return (
        (place.view === 'context'
            ? graph.contexts[place.name]
            : graph.resources[place.name]) ?? graph.overview
    );
}

function open(target: StructureTarget): void {
    window.location.hash = `${target.view}/${encodeURIComponent(target.name)}`;
}

/**
 * The name a card's piece goes by in its manifest: what follows the context or resource in its id.
 */
function nameOf(node: StructureNodeData): string {
    return node.id.slice(node.id.indexOf('/') + 1);
}

/**
 * Whether the panel's form belongs to the view open now, so a form left open elsewhere is not shown.
 */
function belongsHere(editing: Editing, place: StructureTarget | null): boolean {
    switch (editing.kind) {
        case 'piece':
        case 'replace':
        case 'method':
        case 'state':
            return place?.view === 'context' && place.name === editing.context;
        case 'resource-piece':
        case 'resource-settings':
            return (
                place?.view === 'resource' && place.name === editing.resource
            );
        default:
            return true;
    }
}

/**
 * What the browser keeps under a key, or the fallback. The browser may refuse storage (a private
 * window, a policy), and then the screen starts from the fallback every time.
 */
function readStored<T>(key: string, fallback: T): T {
    try {
        const stored = window.localStorage.getItem(key);

        return stored === null ? fallback : (JSON.parse(stored) as T);
    } catch {
        return fallback;
    }
}

function writeStored(key: string, value: unknown): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Storage is off: the screen still works until the page reloads.
    }
}

/**
 * A list the browser remembers, so a reload opens the screen as the developer left it.
 */
function useRememberedList(
    name: string,
    fallback: string[],
): [string[], (list: string[]) => void] {
    const key = `kit.structure.${name}`;
    const [list, setList] = useState(() => readStored(key, fallback));

    const remember = (next: string[]) => {
        setList(next);
        writeStored(key, next);
    };

    return [list, remember];
}

/**
 * The canvas of the view open now as the developer left it, which the browser keeps per view.
 * `update` changes it, and keeps the change unless `keep` is false: a drag changes it on every
 * move, and is kept once the card is put down.
 */
function useCanvasLayout(
    hash: string,
): [
    CanvasLayout,
    (change: (canvas: CanvasLayout) => CanvasLayout, keep?: boolean) => void,
] {
    const key = `kit.structure.layout:${hash === '' ? '#' : hash}`;
    const load = (): CanvasLayout => ({
        ...EMPTY_LAYOUT,
        ...readStored<Partial<CanvasLayout>>(key, {}),
    });
    const [state, setState] = useState(() => ({ key, canvas: load() }));
    let current = state;

    if (state.key !== key) {
        current = { key, canvas: load() };
        setState(current);
    }

    // A resize ends in the same moment as its last change, before the screen draws it again, so
    // a change builds on the latest one rather than on what was drawn.
    const latest = useRef(state);

    const update = (
        change: (canvas: CanvasLayout) => CanvasLayout,
        keep = true,
    ) => {
        const next = change(
            latest.current.key === key ? latest.current.canvas : current.canvas,
        );

        latest.current = { key, canvas: next };
        setState(latest.current);

        if (keep) {
            writeStored(key, next);
        }
    };

    return [current.canvas, update];
}

function toggled(list: string[], id: string): string[] {
    return list.includes(id)
        ? list.filter((other) => other !== id)
        : [...list, id];
}

/**
 * The structure manifest as a diagram: the whole project, then one context or HTTP resource at a
 * time. Every node, edge and status comes from StructureGraph. The pieces the code does not have
 * yet are changed through the side panel, and each change answers with the graph again, which
 * the screen swaps in where it stands: the cards keep their places, the card in hand stays
 * selected, and a card the change adds is selected and brought into view.
 */
export function StructureScreen({ payload }: { payload: StructurePayload }) {
    const [graph, setGraph] = useState(payload.graph);
    const [editing, setEditing] = useState<Editing | null>(null);
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [hiddenInContext, setHiddenInContext] = useRememberedList(
        'hidden.context',
        HIDDEN_IN_CONTEXT,
    );
    const [hiddenInResource, setHiddenInResource] = useRememberedList(
        'hidden.resource',
        [],
    );
    const [hoveredId, setHoveredId] = useState<string | null>(null);
    const [inHand, setInHand] = useState(NOTHING_IN_HAND);
    const flow = useReactFlow<CanvasNode>();
    const focus = useRef<string | null>(null);
    const hash = useSyncExternalStore(
        subscribeToHash,
        () => window.location.hash,
        () => '',
    );
    const place = placeOf(hash);
    const context = place?.view === 'context' ? place.name : null;
    const resource = place?.view === 'resource' ? place.name : null;
    const shared = context === SHARED;
    const fullView = viewOf(graph, place);
    const [canvas, updateCanvas] = useCanvasLayout(hash);
    const filter =
        context !== null && !shared
            ? { hidden: hiddenInContext, set: setHiddenInContext }
            : resource !== null
              ? { hidden: hiddenInResource, set: setHiddenInResource }
              : null;
    const hidden = filter?.hidden ?? NO_LOOKS;
    const { view, laidOut, lines, placed } = useMemo(() => {
        const shown = withoutLooks(viewOf(graph, placeOf(hash)), hidden);
        const drawn = layoutView(
            shown,
            `${hash}|${hidden.join(',')}`,
            canvas,
            inHand,
        );

        return {
            view: shown,
            laidOut: drawn.nodes,
            lines: drawn.edges,
            placed: drawn.placed,
        };
    }, [graph, hidden, hash, canvas, inHand]);
    const lit = focusOf(lines, hoveredId ?? selectedId);
    const nodes = laidOut.map((node): CanvasNode => {
        const dim =
            lit !== null &&
            (node.type === 'frame'
                ? !node.data.members.some((id) => lit.nodes.has(id))
                : !lit.nodes.has(node.id));
        const style = {
            ...node.style,
            opacity: dim ? 0.25 : 1,
            transition: 'opacity 150ms',
        };

        return node.type === 'frame'
            ? { ...node, style }
            : { ...node, style, selected: node.id === selectedId };
    });
    const edges = lines.map((edge) => {
        if (lit === null) {
            return edge;
        }

        return lit.edges.has(edge.id)
            ? {
                  ...edge,
                  animated: true,
                  zIndex: 1,
                  style: {
                      ...edge.style,
                      stroke: 'var(--ring)',
                      strokeWidth: 2,
                  },
              }
            : { ...edge, className: 'opacity-15' };
    });
    const selected = view.nodes.find((node) => node.id === selectedId) ?? null;
    const shownCards = laidOut.filter((node) => node.type === 'structure');
    const allFolded =
        shownCards.length > 0 &&
        shownCards.every((node) => canvas.folded.includes(node.id));
    const panel =
        editing !== null && belongsHere(editing, place) ? editing : null;
    const endpoints = payload.endpoints;

    useEffect(() => {
        const id = focus.current;

        if (id === null) {
            return;
        }

        focus.current = null;
        const node = nodes.find((candidate) => candidate.id === id);

        if (node !== undefined) {
            void flow.setCenter(
                node.position.x + (node.width ?? NODE_WIDTH) / 2,
                node.position.y + (node.height ?? 0) / 2,
                { zoom: flow.getZoom(), duration: 300 },
            );
        }
    }, [nodes, flow]);

    /**
     * Swaps in the graph a change answered with, closes the form, and says what happened. The
     * selection stays. After a form adds a card to the view open now, that card is selected and
     * brought into view; a change made inside the panel (a child, a row removed) leaves the
     * panel on the card it was made from.
     */
    const changed = (
        next: StructureGraph,
        message: string,
        follow: boolean,
    ) => {
        const before = new Set(fullView.nodes.map((node) => node.id));
        const added = follow
            ? viewOf(next, place).nodes.find(
                  (node) => !before.has(node.id) && node.kind !== 'external',
              )
            : undefined;

        setGraph(next);
        setEditing(null);

        if (added !== undefined) {
            setSelectedId(added.id);
            focus.current = added.id;
        }

        toast.success(message);
    };

    const changedHere: Changed = (next, message) =>
        changed(next, message, false);

    const saved = (next: StructureGraph, name?: string) =>
        changed(next, name === undefined ? 'Saved' : `Saved ${name}`, true);

    const [running, setRunning] = useState<CommandRequest | null>(null);
    const reload = useHttp<Record<string, never>, { graph: StructureGraph }>(
        {},
    );

    /**
     * Draws the graph again after a command ran. The command ran in a request of its own, which
     * may hold classes as they were before it wrote; this request reads the code as it is now.
     */
    const commandFinished = (label: string) => {
        reload.get(endpoints.graph, {
            onSuccess: (response) =>
                changed(
                    response.graph,
                    `${label} finished: graph refreshed`,
                    false,
                ),
        });
    };

    /**
     * Shows looks the context view leaves out, so a card a form just saved is on the canvas.
     */
    const reveal = (looks: string[]) =>
        setHiddenInContext(
            hiddenInContext.filter((look) => !looks.includes(look)),
        );

    /**
     * Where every card is drawn now, kept as the developer's own once they move one, with the
     * places of the cards a filter or a collapse leaves out.
     */
    const pinned = (positions: Record<string, XYPosition>) => ({
        ...positions,
        ...placed,
    });

    /**
     * Follows a drag and a resize. A card in hand claims its room first, and dragging a frame
     * drags every card in it. What is in hand is kept once it is put down.
     */
    const onNodesChange = (changes: NodeChange<CanvasNode>[]) => {
        const moves: Record<string, XYPosition> = {};
        const sizes: Record<string, CardSize> = {};

        for (const change of changes) {
            if (change.type === 'position' && change.position !== undefined) {
                const frame = laidOut.find(
                    (node) => node.id === change.id && node.type === 'frame',
                );

                if (frame === undefined) {
                    moves[change.id] = change.position;
                    continue;
                }

                const dx = change.position.x - frame.position.x;
                const dy = change.position.y - frame.position.y;

                for (const member of frame.type === 'frame'
                    ? frame.data.members
                    : []) {
                    const at = placed[member] ?? { x: 0, y: 0 };

                    moves[member] = { x: at.x + dx, y: at.y + dy };
                }
            }

            if (
                change.type === 'dimensions' &&
                change.resizing !== undefined &&
                change.dimensions !== undefined
            ) {
                sizes[change.id] = change.dimensions;
            }
        }

        const ids = Object.keys(moves);

        if (ids.length === 0 && Object.keys(sizes).length === 0) {
            return;
        }

        updateCanvas(
            (current) => ({
                ...current,
                positions: {
                    ...(inHand.size === 0
                        ? pinned(current.positions)
                        : current.positions),
                    ...moves,
                },
                sizes: { ...current.sizes, ...sizes },
            }),
            false,
        );

        if (ids.some((id) => !inHand.has(id))) {
            setInHand(new Set([...inHand, ...ids]));
        }
    };

    /**
     * Keeps the canvas as it is drawn now, once a card is put down or resized.
     */
    const putDown = () => {
        updateCanvas((current) => ({
            ...current,
            positions: pinned(current.positions),
        }));
        setInHand(NOTHING_IN_HAND);
    };

    const actions: CardActions = {
        fold: (id) =>
            updateCanvas((current) => ({
                ...current,
                folded: toggled(current.folded, id),
            })),
        collapse: (id) =>
            updateCanvas((current) => ({
                ...current,
                collapsed: toggled(current.collapsed, id),
            })),
        resized: putDown,
    };

    /**
     * Folds every card, or unfolds them all once they are. A view the developer has not arranged
     * is laid out afresh for the new heights.
     */
    const foldAll = () => {
        const ids = shownCards.map((node) => node.id);

        if (Object.keys(canvas.positions).length === 0) {
            forgetPlaced(`${hash}|`);
        }

        updateCanvas((current) => ({
            ...current,
            folded: allFolded
                ? current.folded.filter((id) => !ids.includes(id))
                : [...new Set([...current.folded, ...ids])],
        }));
    };

    /**
     * Forgets where the developer put the cards of this view and the sizes they gave them, and
     * lays it out afresh.
     */
    const resetLayout = () => {
        forgetPlaced(`${hash}|`);
        updateCanvas((current) => ({ ...current, positions: {}, sizes: {} }));
        window.requestAnimationFrame(
            () => void flow.fitView({ ...FIT_VIEW, duration: 300 }),
        );
    };

    return (
        <div className="flex h-screen flex-col bg-background text-foreground">
            <StructureToolbar
                place={place}
                shared={shared}
                endpoints={endpoints}
                commands={payload.commands}
                onEdit={setEditing}
                onRun={setRunning}
            />
            <div className="flex min-h-0 flex-1">
                <main className="min-w-0 flex-1">
                    <CardActionsContext value={actions}>
                        <ReactFlow
                            key={hash}
                            nodes={nodes}
                            edges={edges}
                            nodeTypes={NODE_TYPES}
                            nodesConnectable={false}
                            colorMode="system"
                            fitView
                            fitViewOptions={FIT_VIEW}
                            zoomOnDoubleClick={false}
                            minZoom={0.1}
                            onNodesChange={onNodesChange}
                            onNodeDragStop={putDown}
                            onNodeClick={(_, node) =>
                                node.type === 'structure' &&
                                setSelectedId(node.id)
                            }
                            onNodeDoubleClick={(_, node) =>
                                node.type === 'structure' &&
                                node.data.target !== null &&
                                open(node.data.target)
                            }
                            onNodeMouseEnter={(_, node) =>
                                node.type === 'structure' &&
                                setHoveredId(node.id)
                            }
                            onNodeMouseLeave={() => setHoveredId(null)}
                            onPaneClick={() => setSelectedId(null)}
                        >
                            <Legend
                                nodes={
                                    filter === null
                                        ? view.nodes
                                        : fullView.nodes
                                }
                                hidden={hidden}
                                onToggle={
                                    filter === null
                                        ? undefined
                                        : (look) =>
                                              filter.set(toggled(hidden, look))
                                }
                                onShowAll={
                                    filter === null
                                        ? undefined
                                        : () => filter.set([])
                                }
                            />
                            <Background />
                            <Controls showInteractive={false}>
                                <ControlButton
                                    title={
                                        allFolded
                                            ? 'Unfold every card'
                                            : 'Fold every card'
                                    }
                                    aria-label={
                                        allFolded
                                            ? 'Unfold every card'
                                            : 'Fold every card'
                                    }
                                    onClick={foldAll}
                                >
                                    {allFolded ? (
                                        <UnfoldVertical style={STROKED} />
                                    ) : (
                                        <FoldVertical style={STROKED} />
                                    )}
                                </ControlButton>
                                <ControlButton
                                    title="Reset layout"
                                    aria-label="Reset layout"
                                    onClick={resetLayout}
                                >
                                    <RotateCcw style={STROKED} />
                                </ControlButton>
                            </Controls>
                            <MiniMap pannable zoomable />
                        </ReactFlow>
                    </CardActionsContext>
                </main>
                <aside className="w-96 shrink-0 space-y-6 overflow-y-auto border-l p-4 text-sm">
                    {panel?.kind === 'context' && (
                        <ContextForm
                            endpoints={endpoints}
                            onSaved={(next, name) => {
                                saved(next, name);
                                open({ view: 'context', name });
                            }}
                            onCancel={() => setEditing(null)}
                        />
                    )}
                    {panel?.kind === 'resource' && (
                        <NewResourceForm
                            endpoints={endpoints}
                            onSaved={(next, name) => {
                                saved(next, name);
                                open({ view: 'resource', name });
                            }}
                            onCancel={() => setEditing(null)}
                        />
                    )}
                    {panel?.kind === 'piece' &&
                        (panel.section === 'enums' ||
                            panel.section === 'valueObjects') && (
                            <VocabularyForm
                                key={`${panel.section}:${panel.previous ?? ''}`}
                                graph={graph}
                                endpoints={endpoints}
                                context={panel.context}
                                section={panel.section}
                                previous={panel.previous}
                                onSaved={(next, name) => {
                                    reveal(VOCABULARY);
                                    saved(next, name);
                                }}
                                onCancel={() => setEditing(null)}
                            />
                        )}
                    {panel?.kind === 'piece' &&
                        panel.section === 'exceptions' && (
                            <ExceptionForm
                                key={panel.previous ?? ''}
                                graph={graph}
                                endpoints={endpoints}
                                context={panel.context}
                                previous={panel.previous}
                                onSaved={(next, name) => {
                                    reveal(['exception']);
                                    saved(next, name);
                                }}
                                onCancel={() => setEditing(null)}
                            />
                        )}
                    {panel?.kind === 'piece' &&
                        panel.section !== 'enums' &&
                        panel.section !== 'valueObjects' &&
                        panel.section !== 'exceptions' && (
                            <PieceForm
                                key={`${panel.section}:${panel.previous ?? ''}`}
                                graph={graph}
                                endpoints={endpoints}
                                context={panel.context}
                                section={panel.section}
                                previous={panel.previous}
                                onSaved={saved}
                                onCancel={() => setEditing(null)}
                            />
                        )}
                    {panel?.kind === 'method' && (
                        <MethodForm
                            key={`${panel.entity ?? ''}:${panel.previous ?? ''}`}
                            graph={graph}
                            endpoints={endpoints}
                            context={panel.context}
                            entity={panel.entity}
                            previous={panel.previous}
                            onSaved={(next) => {
                                reveal(['entity']);
                                saved(next);
                            }}
                            onCancel={() => setEditing(null)}
                        />
                    )}
                    {panel?.kind === 'state' && (
                        <StateForm
                            key={`${panel.entity}:${panel.previous ?? ''}`}
                            graph={graph}
                            endpoints={endpoints}
                            context={panel.context}
                            entity={panel.entity}
                            previous={panel.previous}
                            onSaved={(next) => {
                                reveal(['entity']);
                                saved(next);
                            }}
                            onCancel={() => setEditing(null)}
                        />
                    )}
                    {panel?.kind === 'resource-piece' && (
                        <ResourcePieceForm
                            key={`${panel.section}:${panel.previous ?? ''}`}
                            graph={graph}
                            endpoints={endpoints}
                            resource={panel.resource}
                            section={panel.section}
                            previous={panel.previous}
                            onSaved={(next) => saved(next)}
                            onCancel={() => setEditing(null)}
                        />
                    )}
                    {panel?.kind === 'replace' && (
                        <ReplaceForm
                            key={`${panel.section}:${panel.name}`}
                            graph={graph}
                            endpoints={endpoints}
                            context={panel.context}
                            section={panel.section}
                            name={panel.name}
                            onSaved={(next) => saved(next)}
                            onCancel={() => setEditing(null)}
                        />
                    )}
                    {panel?.kind === 'resource-settings' && (
                        <div className="space-y-4">
                            <ResourceSettingsForm
                                graph={graph}
                                endpoints={endpoints}
                                resource={panel.resource}
                                onSaved={(next) => saved(next)}
                                onCancel={() => setEditing(null)}
                            />
                            <ResourceMenuForm
                                key={graph.resourceVersions[panel.resource]}
                                graph={graph}
                                endpoints={endpoints}
                                resource={panel.resource}
                                onSaved={(next) => saved(next)}
                            />
                        </div>
                    )}
                    {panel === null &&
                        (selected === null ? (
                            <p className="text-muted-foreground">
                                Select a card to see its status.
                            </p>
                        ) : (
                            <Details
                                key={selected.id}
                                node={selected}
                                graph={graph}
                                endpoints={endpoints}
                                context={context}
                                resource={resource}
                                onEdit={setEditing}
                                onRemoved={(next, message) => {
                                    setSelectedId(null);
                                    changedHere(next, message);
                                }}
                                onChanged={changedHere}
                                onSelect={(id) => {
                                    reveal(['entity']);
                                    setSelectedId(id);
                                    focus.current = id;
                                }}
                            />
                        ))}
                    <ByHand graph={graph} />
                </aside>
            </div>
            {running !== null && (
                <CommandDialog
                    key={`${running.command.key}:${running.scope?.name ?? ''}`}
                    request={running}
                    endpoints={endpoints}
                    onClose={() => setRunning(null)}
                    onFinished={() => commandFinished(running.command.label)}
                />
            )}
        </div>
    );
}

/**
 * The entity methods, in any context, that throw an exception this context designs: by its bare
 * name inside its own aggregate, or as `Shared/Name` or `Context/Aggregate/Name` from elsewhere.
 */
function throwersOf(
    graph: StructureGraph,
    context: string,
    name: string,
): string[] {
    const holder =
        graph.manifests[context]?.exceptions[name]?.aggregate ?? null;
    const qualified =
        context === SHARED
            ? `${SHARED}/${name}`
            : `${context}/${holder}/${name}`;
    const throwers: string[] = [];

    for (const [owner, manifest] of Object.entries(graph.manifests)) {
        for (const [entity, entry] of Object.entries(
            manifest?.entities ?? {},
        )) {
            if (entry === undefined) {
                continue;
            }

            const names =
                owner === context && entry.aggregate === holder
                    ? [name, qualified]
                    : [qualified];

            for (const [method, definition] of Object.entries({
                ...entry.behaviours,
                ...entry.assertions,
            })) {
                if (
                    definition?.throws.some((thrown) => names.includes(thrown))
                ) {
                    throwers.push(
                        `${owner === context ? '' : `${owner}/`}${entity}::${method}()`,
                    );
                }
            }
        }
    }

    return throwers;
}

/**
 * What replacing a port's adapter or a use case looks like from its card: whether it can start,
 * and whether one is under way that can be cancelled.
 */
function replacementOf(
    graph: StructureGraph,
    context: string,
    section: ReplaceableSection,
    name: string,
): { section: ReplaceableSection; canStart: boolean; replacing: boolean } {
    const manifest = graph.manifests[context];
    const entries = manifest?.[section] ?? {};
    const entry = entries[name];
    const replacing = typeof entry?.replaces === 'string';
    const replaced = Object.values(entries).some(
        (other) => other?.replaces === name,
    );
    const fits =
        section !== 'ports' ||
        typeof manifest?.ports[name]?.adapter === 'string';

    return {
        section,
        canStart: entry !== undefined && fits && !replacing && !replaced,
        replacing,
    };
}

/**
 * What a card says beyond its title, as the panel lays it out: an enum's cases and moves and a
 * value object's fields as tables read from the manifest (the card cuts them at six), an
 * exception's methods that throw it, and any other card's lines as they are.
 */
function CardContents({
    graph,
    context,
    node,
}: {
    graph: StructureGraph;
    context: string | null;
    node: StructureNodeData;
}) {
    const manifest = context === null ? undefined : graph.manifests[context];
    const name = nameOf(node);
    const lines =
        node.items.length > 0 ? (
            <div className="space-y-0.5 text-xs text-muted-foreground">
                {node.items.map((item) => (
                    <div key={item} className="break-all">
                        {item}
                    </div>
                ))}
            </div>
        ) : null;

    if (node.kind === 'enum' && manifest?.enums[name] !== undefined) {
        const entry = manifest.enums[name];
        const transitions = entry.transitions;

        return (
            <>
                <PanelSection title={`Cases · ${entry.backing ?? 'pure'}`}>
                    <PanelTable head={['Case', 'Value']} empty="No cases yet.">
                        {Object.entries(entry.cases).map(([value, backed]) => (
                            <PanelRow
                                key={value}
                                cells={[
                                    value,
                                    backed === null ? '—' : String(backed),
                                ]}
                            />
                        ))}
                    </PanelTable>
                </PanelSection>
                {transitions !== null && (
                    <PanelSection title="Moves">
                        <PanelTable
                            head={['Case', 'May become']}
                            empty="No moves yet."
                        >
                            {Object.entries(transitions).map(
                                ([value, next]) => (
                                    <PanelRow
                                        key={value}
                                        cells={[
                                            value,
                                            next.length === 0 ? (
                                                <span
                                                    key="final"
                                                    className="text-muted-foreground"
                                                >
                                                    final
                                                </span>
                                            ) : (
                                                next.join(', ')
                                            ),
                                        ]}
                                    />
                                ),
                            )}
                        </PanelTable>
                    </PanelSection>
                )}
            </>
        );
    }

    if (
        node.kind === 'valueObject' &&
        manifest?.valueObjects[name] !== undefined
    ) {
        return (
            <PanelSection title="Fields">
                <PanelTable head={['Field', 'Type']} empty="No fields yet.">
                    {Object.entries(manifest.valueObjects[name].fields).map(
                        ([field, type]) => (
                            <PanelRow key={field} cells={[field, type]} />
                        ),
                    )}
                </PanelTable>
            </PanelSection>
        );
    }

    if (node.kind === 'exception' && context !== null) {
        return (
            <>
                {lines}
                <PanelSection title="Thrown by">
                    <PanelTable
                        head={['Method']}
                        empty="No method throws it yet."
                    >
                        {throwersOf(graph, context, name).map((thrower) => (
                            <PanelRow key={thrower} cells={[thrower]} />
                        ))}
                    </PanelTable>
                </PanelSection>
            </>
        );
    }

    if (node.kind === 'controller' || node.kind === 'entity') {
        return null;
    }

    return lines;
}

function Details({
    node,
    graph,
    endpoints,
    context,
    resource,
    onEdit,
    onRemoved,
    onChanged,
    onSelect,
}: {
    node: StructureNodeData;
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    context: string | null;
    resource: string | null;
    onEdit: (editing: Editing) => void;
    onRemoved: Changed;
    onChanged: Changed;
    onSelect: (id: string) => void;
}) {
    const contextSection = CONTEXT_SECTIONS[node.kind];
    const resourceSection = RESOURCE_SECTIONS[node.kind];
    const name = nameOf(node);
    const look = styleOf(node);
    const Icon = look.icon;
    const replaceable =
        context !== null &&
        (contextSection === 'ports' ||
            contextSection === 'useCases' ||
            contextSection === 'services')
            ? replacementOf(graph, context, contextSection, name)
            : null;
    const actions: ReactNode[] = [];

    if (node.target !== null) {
        const target = node.target;

        actions.push(
            <IconAction
                key="open"
                icon={ArrowUpRight}
                label={`Open ${target.name}`}
                onClick={() => open(target)}
            />,
        );
    }

    if (contextSection !== undefined && context !== null && node.editable) {
        actions.push(
            <IconAction
                key="edit"
                icon={Pencil}
                label={`Edit ${name}`}
                onClick={() =>
                    onEdit({
                        kind: 'piece',
                        context,
                        section: contextSection,
                        previous: name,
                    })
                }
            />,
            <RemoveButton
                key="remove"
                url={endpointFor(endpoints.removePiece, context)}
                version={graph.versions[context] ?? ''}
                section={contextSection}
                name={name}
                owner={context}
                onRemoved={onRemoved}
            />,
        );
    }

    if (resourceSection !== undefined && resource !== null && node.editable) {
        actions.push(
            <IconAction
                key="edit"
                icon={Pencil}
                label={`Edit ${name}`}
                onClick={() =>
                    onEdit({
                        kind: 'resource-piece',
                        resource,
                        section: resourceSection,
                        previous: name,
                    })
                }
            />,
            <RemoveButton
                key="remove"
                url={endpointFor(endpoints.removeResourcePiece, resource)}
                version={graph.resourceVersions[resource] ?? ''}
                section={resourceSection}
                name={name}
                owner={resource}
                onRemoved={onRemoved}
            />,
        );
    }

    if (
        (node.kind === 'model' || node.kind === 'policy') &&
        resource !== null &&
        node.editable
    ) {
        actions.push(
            <IconAction
                key="settings"
                icon={Settings2}
                label="Edit model and policy"
                onClick={() => onEdit({ kind: 'resource-settings', resource })}
            />,
        );
    }

    if (
        contextSection !== undefined &&
        context !== null &&
        (graph.outOfStep[context] ?? []).includes(`${contextSection}.${name}`)
    ) {
        actions.push(
            <SyncButton
                key="sync"
                url={endpointFor(endpoints.syncPiece, context)}
                version={graph.versions[context] ?? ''}
                section={contextSection}
                name={name}
                onSynced={onChanged}
            />,
        );
    }

    if (
        resource !== null &&
        (resourceSection !== undefined ||
            node.kind === 'model' ||
            node.kind === 'policy') &&
        (graph.resourceOutOfStep[resource] ?? []).includes(
            resourceSection !== undefined
                ? `${resourceSection}.${name}`
                : node.kind,
        )
    ) {
        actions.push(
            <SyncButton
                key="sync"
                url={endpointFor(endpoints.syncResourcePiece, resource)}
                version={graph.resourceVersions[resource] ?? ''}
                section={resourceSection ?? node.kind}
                name={resourceSection !== undefined ? name : ''}
                onSynced={onChanged}
            />,
        );
    }

    if (
        replaceable !== null &&
        context !== null &&
        !node.editable &&
        replaceable.canStart
    ) {
        actions.push(
            <IconAction
                key="replace"
                icon={Replace}
                label={`Replace ${name}`}
                onClick={() =>
                    onEdit({
                        kind: 'replace',
                        context,
                        section: replaceable.section,
                        name,
                    })
                }
            />,
        );
    }

    if (replaceable !== null && context !== null && replaceable.replacing) {
        actions.push(
            <CancelReplacementButton
                key="cancel-replacement"
                graph={graph}
                endpoints={endpoints}
                context={context}
                section={replaceable.section}
                name={name}
                onCancelled={onChanged}
            />,
        );
    }

    const command = node.command;

    return (
        <section className="space-y-5">
            <div className="space-y-2">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                        <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Icon className={`size-3.5 ${look.accent}`} />
                            {KIND_LABELS[node.kind]}
                        </div>
                        <div className="font-medium break-all">
                            {node.label}
                        </div>
                    </div>
                    {node.status !== null && (
                        <StatusBadge status={node.status} />
                    )}
                </div>
                {actions.length > 0 && (
                    <div className="-ml-1.5 flex flex-wrap items-center">
                        {actions}
                    </div>
                )}
                {node.reason !== null && (
                    <p className="text-xs text-muted-foreground">
                        {node.reason}
                    </p>
                )}
                {command !== null && (
                    <div className="flex items-start gap-1 rounded-md bg-muted p-2">
                        <code className="min-w-0 flex-1 text-xs break-all whitespace-pre-wrap">
                            {command}
                        </code>
                        <IconAction
                            icon={Copy}
                            label="Copy the command"
                            className="-my-1 size-6"
                            onClick={() =>
                                void navigator.clipboard
                                    .writeText(command)
                                    .then(() => toast.success('Copied'))
                            }
                        />
                    </div>
                )}
                {(contextSection !== undefined ||
                    resourceSection !== undefined) &&
                    !node.editable && (
                        <p className="text-xs text-muted-foreground">
                            The code already has it. Change the code and sync
                            it, or replace it.
                        </p>
                    )}
            </div>
            <CardContents graph={graph} context={context} node={node} />
            {node.kind === 'aggregate' && context !== null && (
                <AggregateChildren
                    graph={graph}
                    endpoints={endpoints}
                    context={context}
                    aggregate={name}
                    onChanged={onChanged}
                    onSelect={onSelect}
                />
            )}
            {node.kind === 'entity' && context !== null && (
                <>
                    <ChildOf
                        graph={graph}
                        endpoints={endpoints}
                        context={context}
                        entity={name}
                        removable={node.editable}
                        onSelect={onSelect}
                        onRemoved={onRemoved}
                    />
                    <EntityState
                        graph={graph}
                        endpoints={endpoints}
                        context={context}
                        entity={name}
                        onEdit={onEdit}
                        onChanged={onChanged}
                    />
                    <EntityMethods
                        graph={graph}
                        endpoints={endpoints}
                        context={context}
                        entity={name}
                        onEdit={onEdit}
                        onChanged={onChanged}
                    />
                </>
            )}
            {node.kind === 'controller' && resource !== null && (
                <ControllerMethods
                    graph={graph}
                    endpoints={endpoints}
                    resource={resource}
                    onEdit={onEdit}
                    onChanged={onChanged}
                />
            )}
        </section>
    );
}
