import {
    Background,
    Controls,
    MiniMap,
    ReactFlow,
    useReactFlow,
} from '@xyflow/react';
import { ArrowUpRight, Copy, Pencil, Replace, Settings2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { toast } from 'sonner';
import { ContextForm } from '@/kit/context-form';
import { ExceptionForm } from '@/kit/exception-form';
import { layoutView, NODE_WIDTH, withoutKinds } from '@/kit/layout';
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
import { NewResourceForm, ResourceSettingsForm } from '@/kit/resource-form';
import { ResourcePieceForm } from '@/kit/resource-piece-form';
import { StateForm } from '@/kit/state-form';
import { KIND_LABELS, StatusBadge, StructureNode } from '@/kit/structure-node';
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

const NODE_TYPES = { structure: StructureNode };

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
 * A view switch the browser remembers, so a reload opens the views the developer last had on.
 * The browser may refuse storage (a private window, a policy), and then the switch starts off.
 */
function useRememberedSwitch(name: string): [boolean, (on: boolean) => void] {
    const key = `kit.structure.${name}`;
    const [on, setOn] = useState(() => {
        try {
            return window.localStorage.getItem(key) === '1';
        } catch {
            return false;
        }
    });

    const remember = (next: boolean) => {
        setOn(next);

        try {
            window.localStorage.setItem(key, next ? '1' : '0');
        } catch {
            // Storage is off: the switch still works until the page reloads.
        }
    };

    return [on, remember];
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
    const [showVocabulary, setShowVocabulary] =
        useRememberedSwitch('vocabulary');
    const [showBehaviour, setShowBehaviour] = useRememberedSwitch('behaviour');
    const [showExceptions, setShowExceptions] =
        useRememberedSwitch('exceptions');
    const flow = useReactFlow();
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
    const hidden = (
        context === null || shared
            ? []
            : [
                  ...(showVocabulary ? [] : ['enum', 'valueObject']),
                  ...(showBehaviour ? [] : ['entity']),
                  ...(showExceptions ? [] : ['exception']),
              ]
    ).join(',');
    const view = withoutKinds(
        fullView,
        hidden === '' ? [] : (hidden.split(',') as StructureNodeKind[]),
    );
    const { nodes, edges } = layoutView(view, `${hash}|${hidden}`);
    const selected = view.nodes.find((node) => node.id === selectedId) ?? null;
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
                node.position.x + NODE_WIDTH / 2,
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

    const switches =
        context === null || shared
            ? []
            : [
                  {
                      label: 'Vocabulary',
                      icon: 'enum' as const,
                      on: showVocabulary,
                      set: setShowVocabulary,
                  },
                  {
                      label: 'Behaviour',
                      icon: 'entity' as const,
                      on: showBehaviour,
                      set: setShowBehaviour,
                  },
                  {
                      label: 'Exceptions',
                      icon: 'exception' as const,
                      on: showExceptions,
                      set: setShowExceptions,
                  },
              ];

    return (
        <div className="flex h-screen flex-col bg-background text-foreground">
            <StructureToolbar
                place={place}
                shared={shared}
                endpoints={endpoints}
                switches={switches}
                onEdit={setEditing}
            />
            <div className="flex min-h-0 flex-1">
                <main className="min-w-0 flex-1">
                    <ReactFlow
                        key={hash}
                        nodes={nodes}
                        edges={edges}
                        nodeTypes={NODE_TYPES}
                        nodesDraggable={false}
                        nodesConnectable={false}
                        colorMode="system"
                        fitView
                        fitViewOptions={FIT_VIEW}
                        zoomOnDoubleClick={false}
                        minZoom={0.1}
                        onNodeClick={(_, node) => setSelectedId(node.id)}
                        onNodeDoubleClick={(_, node) =>
                            node.data.target !== null && open(node.data.target)
                        }
                        onPaneClick={() => setSelectedId(null)}
                    >
                        <Legend nodes={view.nodes} />
                        <Background />
                        <Controls showInteractive={false} />
                        <MiniMap pannable zoomable />
                    </ReactFlow>
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
                                    setShowVocabulary(true);
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
                                    setShowExceptions(true);
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
                                setShowBehaviour(true);
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
                                setShowBehaviour(true);
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
                        <ResourceSettingsForm
                            graph={graph}
                            endpoints={endpoints}
                            resource={panel.resource}
                            onSaved={(next) => saved(next)}
                            onCancel={() => setEditing(null)}
                        />
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
                                    setShowBehaviour(true);
                                    setSelectedId(id);
                                    focus.current = id;
                                }}
                            />
                        ))}
                    <ByHand graph={graph} />
                </aside>
            </div>
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
