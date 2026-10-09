import { useHttp } from '@inertiajs/react';
import { Background, Controls, MiniMap, ReactFlow } from '@xyflow/react';
import { useState, useSyncExternalStore } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { ContextForm } from '@/kit/context-form';
import { ExceptionForm } from '@/kit/exception-form';
import { layoutView, withoutKinds } from '@/kit/layout';
import { Legend } from '@/kit/legend';
import { MethodForm, methodOf } from '@/kit/method-form';
import { PieceForm, SECTION_LABELS } from '@/kit/piece-form';
import { CancelReplacementButton, ReplaceForm } from '@/kit/replace-form';
import { NewResourceForm, ResourceSettingsForm } from '@/kit/resource-form';
import {
    RESOURCE_SECTION_LABELS,
    ResourcePieceForm,
} from '@/kit/resource-piece-form';
import { KIND_LABELS, StatusBadge, StructureNode } from '@/kit/structure-node';
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

const SHARED_SECTIONS: StructureSection[] = [
    'enums',
    'valueObjects',
    'exceptions',
];

const RESOURCE_SECTIONS: Partial<Record<StructureNodeKind, ResourceSection>> = {
    action: 'actions',
    page: 'pages',
};

/**
 * What the side panel edits: a piece of the context or resource open now, the resource's
 * settings, or a new context or resource.
 */
type Editing =
    | {
          kind: 'piece';
          context: string;
          section: StructureSection;
          previous: string | null;
      }
    | {
          kind: 'resource-piece';
          resource: string;
          section: ResourceSection;
          previous: string | null;
      }
    | { kind: 'resource-settings'; resource: string }
    | { kind: 'context' }
    | { kind: 'resource' }
    | {
          kind: 'replace';
          context: string;
          section: ReplaceableSection;
          name: string;
      }
    | {
          kind: 'method';
          context: string;
          entity: string | null;
          previous: string | null;
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
 * yet are changed through the side panel, and each change answers with the graph again.
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
    const hash = useSyncExternalStore(
        subscribeToHash,
        () => window.location.hash,
        () => '',
    );
    const place = placeOf(hash);
    const context = place?.view === 'context' ? place.name : null;
    const shared = context === SHARED;
    const fullView = viewOf(graph, place);
    const hidden: StructureNodeKind[] =
        context === null || shared
            ? []
            : [
                  ...(showVocabulary
                      ? []
                      : (['enum', 'valueObject'] as StructureNodeKind[])),
                  ...(showBehaviour ? [] : (['entity'] as StructureNodeKind[])),
                  ...(showExceptions
                      ? []
                      : (['exception'] as StructureNodeKind[])),
              ];
    const view = withoutKinds(fullView, hidden);
    const { nodes, edges } = layoutView(view);
    const selected = view.nodes.find((node) => node.id === selectedId) ?? null;
    const resource = place?.view === 'resource' ? place.name : null;
    const panel =
        editing !== null && belongsHere(editing, place) ? editing : null;
    const endpoints = payload.endpoints;

    const saved = (next: StructureGraph) => {
        setGraph(next);
        setEditing(null);
    };

    return (
        <div className="flex h-screen flex-col bg-background text-foreground">
            <header className="flex items-center justify-between gap-4 border-b px-4 py-3">
                <Breadcrumb>
                    <BreadcrumbList>
                        <BreadcrumbItem>
                            {place === null ? (
                                <BreadcrumbPage>Structure</BreadcrumbPage>
                            ) : (
                                <BreadcrumbLink href="#">
                                    Structure
                                </BreadcrumbLink>
                            )}
                        </BreadcrumbItem>
                        {place !== null && (
                            <>
                                <BreadcrumbSeparator />
                                <BreadcrumbItem>
                                    <BreadcrumbPage>
                                        {place.view === 'context'
                                            ? 'Context'
                                            : 'HTTP resource'}{' '}
                                        {place.name}
                                    </BreadcrumbPage>
                                </BreadcrumbItem>
                            </>
                        )}
                    </BreadcrumbList>
                </Breadcrumb>
                <div className="flex flex-wrap items-center justify-end gap-2">
                    <a
                        href={endpoints.guide}
                        className="text-sm text-muted-foreground hover:text-foreground"
                    >
                        Guide
                    </a>
                    <a
                        href={endpoints.docs}
                        className="mr-2 text-sm text-muted-foreground hover:text-foreground"
                    >
                        Docs
                    </a>
                    {place === null && (
                        <>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setEditing({ kind: 'context' })}
                            >
                                New context
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setEditing({ kind: 'resource' })}
                            >
                                New HTTP resource
                            </Button>
                        </>
                    )}
                    {context !== null && !shared && (
                        <Button
                            size="sm"
                            variant={showVocabulary ? 'secondary' : 'outline'}
                            aria-pressed={showVocabulary}
                            onClick={() => setShowVocabulary(!showVocabulary)}
                        >
                            Vocabulary
                        </Button>
                    )}
                    {context !== null && !shared && (
                        <Button
                            size="sm"
                            variant={showBehaviour ? 'secondary' : 'outline'}
                            aria-pressed={showBehaviour}
                            onClick={() => setShowBehaviour(!showBehaviour)}
                        >
                            Behaviour
                        </Button>
                    )}
                    {context !== null && !shared && (
                        <Button
                            size="sm"
                            variant={showExceptions ? 'secondary' : 'outline'}
                            aria-pressed={showExceptions}
                            onClick={() => setShowExceptions(!showExceptions)}
                        >
                            Exceptions
                        </Button>
                    )}
                    {context !== null &&
                        (shared
                            ? SHARED_SECTIONS
                            : (Object.keys(
                                  SECTION_LABELS,
                              ) as StructureSection[])
                        ).map((section) => (
                            <Button
                                key={section}
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setEditing({
                                        kind: 'piece',
                                        context,
                                        section,
                                        previous: null,
                                    })
                                }
                            >
                                Add {SECTION_LABELS[section]}
                            </Button>
                        ))}
                    {context !== null && !shared && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setEditing({
                                    kind: 'method',
                                    context,
                                    entity: null,
                                    previous: null,
                                })
                            }
                        >
                            Add method
                        </Button>
                    )}
                    {resource !== null && (
                        <>
                            {(
                                Object.keys(
                                    RESOURCE_SECTION_LABELS,
                                ) as ResourceSection[]
                            ).map((section) => (
                                <Button
                                    key={section}
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        setEditing({
                                            kind: 'resource-piece',
                                            resource,
                                            section,
                                            previous: null,
                                        })
                                    }
                                >
                                    Add {RESOURCE_SECTION_LABELS[section]}
                                </Button>
                            ))}
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setEditing({
                                        kind: 'resource-settings',
                                        resource,
                                    })
                                }
                            >
                                Model and policy
                            </Button>
                        </>
                    )}
                </div>
            </header>
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
                        fitViewOptions={{ minZoom: 0.5, maxZoom: 1 }}
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
                                saved(next);
                                open({ view: 'context', name });
                            }}
                            onCancel={() => setEditing(null)}
                        />
                    )}
                    {panel?.kind === 'resource' && (
                        <NewResourceForm
                            endpoints={endpoints}
                            onSaved={(next, name) => {
                                saved(next);
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
                                onSaved={(next) => {
                                    setShowVocabulary(true);
                                    saved(next);
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
                                onSaved={(next) => {
                                    setShowExceptions(true);
                                    saved(next);
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
                                onSaved={(next) => saved(next)}
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
                                onRemoved={(next) => {
                                    setSelectedId(null);
                                    saved(next);
                                }}
                                onChanged={saved}
                                onSelect={(id) => {
                                    setShowBehaviour(true);
                                    setSelectedId(id);
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
 * A card's lines in full. An enum's cases and a value object's fields are cut at six on the card,
 * so the panel reads them from the manifest. A status's card shows its moves in place of its
 * values, so the panel lists both.
 */
function fullItems(
    graph: StructureGraph,
    context: string,
    node: StructureNodeData,
): string[] {
    const manifest = graph.manifests[context];
    const name = nameOf(node);

    if (node.kind === 'enum') {
        const entry = manifest?.enums[name];

        if (entry === undefined) {
            return node.items;
        }

        const transitions = entry.transitions;

        return [
            entry.backing ?? 'pure',
            ...Object.entries(entry.cases).map(([value, backed]) =>
                backed === null ? value : `${value} = ${backed}`,
            ),
            ...(transitions === null
                ? []
                : Object.entries(transitions).map(([value, next]) =>
                      next.length === 0
                          ? `${value} · final`
                          : `${value} → ${next.join(', ')}`,
                  )),
        ];
    }

    if (node.kind === 'exception') {
        return [...node.items, ...throwersOf(graph, context, name)];
    }

    if (node.kind === 'valueObject') {
        const entry = manifest?.valueObjects[name];

        return entry === undefined
            ? node.items
            : Object.entries(entry.fields).map(
                  ([field, type]) => `${field}: ${type}`,
              );
    }

    return node.items;
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
                        `thrown by ${owner === context ? '' : `${owner}/`}${entity}::${method}`,
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
    onRemoved: (graph: StructureGraph) => void;
    onChanged: (graph: StructureGraph) => void;
    onSelect: (id: string) => void;
}) {
    const contextSection = CONTEXT_SECTIONS[node.kind];
    const resourceSection = RESOURCE_SECTIONS[node.kind];
    const name = nameOf(node);
    const replaceable =
        context !== null &&
        (contextSection === 'ports' ||
            contextSection === 'useCases' ||
            contextSection === 'services')
            ? replacementOf(graph, context, contextSection, name)
            : null;

    return (
        <section className="space-y-3">
            <div>
                <div className="text-xs text-muted-foreground">
                    {KIND_LABELS[node.kind]}
                </div>
                <div className="font-medium break-all">{node.label}</div>
            </div>
            {node.status !== null && <StatusBadge status={node.status} />}
            {node.reason !== null && (
                <p className="text-muted-foreground">{node.reason}</p>
            )}
            {node.command !== null && (
                <pre className="rounded-md bg-muted p-2 text-xs break-all whitespace-pre-wrap">
                    {node.command}
                </pre>
            )}
            {node.kind !== 'controller' &&
                node.kind !== 'entity' &&
                node.items.length > 0 && (
                    <ul className="list-inside list-disc text-muted-foreground">
                        {(context !== null
                            ? fullItems(graph, context, node)
                            : node.items
                        ).map((item) => (
                            <li key={item} className="break-all">
                                {item}
                            </li>
                        ))}
                    </ul>
                )}
            {node.target !== null && (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => node.target !== null && open(node.target)}
                >
                    Open {node.target.name}
                </Button>
            )}
            {contextSection !== undefined &&
                context !== null &&
                node.editable && (
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                onEdit({
                                    kind: 'piece',
                                    context,
                                    section: contextSection,
                                    previous: name,
                                })
                            }
                        >
                            Edit
                        </Button>
                        <RemoveButton
                            url={endpoints.removePiece.replace(
                                '__CONTEXT__',
                                encodeURIComponent(context),
                            )}
                            version={graph.versions[context] ?? ''}
                            section={contextSection}
                            name={name}
                            owner={context}
                            onRemoved={onRemoved}
                        />
                    </div>
                )}
            {contextSection !== undefined &&
                context !== null &&
                (graph.outOfStep[context] ?? []).includes(
                    `${contextSection}.${name}`,
                ) && (
                    <SyncButton
                        url={endpoints.syncPiece.replace(
                            '__CONTEXT__',
                            encodeURIComponent(context),
                        )}
                        version={graph.versions[context] ?? ''}
                        section={contextSection}
                        name={name}
                        onSynced={onChanged}
                    />
                )}
            {resource !== null &&
                (resourceSection !== undefined ||
                    node.kind === 'model' ||
                    node.kind === 'policy') &&
                (graph.resourceOutOfStep[resource] ?? []).includes(
                    resourceSection !== undefined
                        ? `${resourceSection}.${name}`
                        : node.kind,
                ) && (
                    <SyncButton
                        url={endpoints.syncResourcePiece.replace(
                            '__RESOURCE__',
                            encodeURIComponent(resource),
                        )}
                        version={graph.resourceVersions[resource] ?? ''}
                        section={resourceSection ?? node.kind}
                        name={resourceSection !== undefined ? name : ''}
                        onSynced={onChanged}
                    />
                )}
            {replaceable !== null &&
                context !== null &&
                !node.editable &&
                replaceable.canStart && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            onEdit({
                                kind: 'replace',
                                context,
                                section: replaceable.section,
                                name,
                            })
                        }
                    >
                        Replace
                    </Button>
                )}
            {replaceable !== null &&
                context !== null &&
                replaceable.replacing && (
                    <CancelReplacementButton
                        graph={graph}
                        endpoints={endpoints}
                        context={context}
                        section={replaceable.section}
                        name={name}
                        onCancelled={onChanged}
                    />
                )}
            {resourceSection !== undefined &&
                resource !== null &&
                node.editable && (
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                onEdit({
                                    kind: 'resource-piece',
                                    resource,
                                    section: resourceSection,
                                    previous: name,
                                })
                            }
                        >
                            Edit
                        </Button>
                        <RemoveButton
                            url={endpoints.removeResourcePiece.replace(
                                '__RESOURCE__',
                                encodeURIComponent(resource),
                            )}
                            version={graph.resourceVersions[resource] ?? ''}
                            section={resourceSection}
                            name={name}
                            owner={resource}
                            onRemoved={onRemoved}
                        />
                    </div>
                )}
            {(node.kind === 'model' || node.kind === 'policy') &&
                resource !== null &&
                node.editable && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            onEdit({ kind: 'resource-settings', resource })
                        }
                    >
                        Edit model and policy
                    </Button>
                )}
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
                <ChildOf
                    graph={graph}
                    endpoints={endpoints}
                    context={context}
                    entity={name}
                    removable={node.editable}
                    onRemoved={onRemoved}
                />
            )}
            {node.kind === 'entity' && context !== null && (
                <EntityMethods
                    graph={graph}
                    endpoints={endpoints}
                    context={context}
                    entity={name}
                    onEdit={onEdit}
                    onRemoved={onChanged}
                />
            )}
            {node.kind === 'controller' && resource !== null && (
                <Methods
                    graph={graph}
                    endpoints={endpoints}
                    resource={resource}
                    onEdit={onEdit}
                    onRemoved={onRemoved}
                />
            )}
            {(contextSection !== undefined || resourceSection !== undefined) &&
                !node.editable && (
                    <p className="text-xs text-muted-foreground">
                        The code already has it. Change the code and sync it, or
                        replace it.
                    </p>
                )}
        </section>
    );
}

/**
 * A controller's methods, one row each, with the use cases it calls. A method the code does not
 * have yet can be changed or removed.
 */
function Methods({
    graph,
    endpoints,
    resource,
    onEdit,
    onRemoved,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    resource: string;
    onEdit: (editing: Editing) => void;
    onRemoved: (graph: StructureGraph) => void;
}) {
    const controller = graph.resourceManifests[resource]?.controller ?? {};
    const built = graph.resourceBuilt[resource] ?? [];
    const outOfStep = graph.resourceOutOfStep[resource] ?? [];

    return (
        <ul className="space-y-3">
            {Object.entries(controller).map(([method, useCases]) => (
                <li key={method} className="space-y-1">
                    <div className="font-mono text-xs">{method}()</div>
                    {(useCases ?? []).map((useCase) => (
                        <div
                            key={useCase}
                            className="text-xs text-muted-foreground"
                        >
                            {useCase}
                        </div>
                    ))}
                    {built.includes(`controller.${method}`) ? (
                        <div className="flex items-center gap-2 text-xs text-muted-foreground">
                            built
                            {outOfStep.includes(`controller.${method}`) && (
                                <SyncButton
                                    url={endpoints.syncResourcePiece.replace(
                                        '__RESOURCE__',
                                        encodeURIComponent(resource),
                                    )}
                                    version={
                                        graph.resourceVersions[resource] ?? ''
                                    }
                                    section="controller"
                                    name={method}
                                    onSynced={onRemoved}
                                />
                            )}
                        </div>
                    ) : (
                        <div className="flex gap-2">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    onEdit({
                                        kind: 'resource-piece',
                                        resource,
                                        section: 'controller',
                                        previous: method,
                                    })
                                }
                            >
                                Edit
                            </Button>
                            <RemoveButton
                                url={endpoints.removeResourcePiece.replace(
                                    '__RESOURCE__',
                                    encodeURIComponent(resource),
                                )}
                                version={graph.resourceVersions[resource] ?? ''}
                                section="controller"
                                name={method}
                                owner={resource}
                                onRemoved={onRemoved}
                            />
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}

/**
 * Which aggregate a child entity belongs to, and its Remove while the code does not have it yet
 * and it lists no method. A root says nothing here: its aggregate's card holds it.
 */
function ChildOf({
    graph,
    endpoints,
    context,
    entity,
    removable,
    onRemoved,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    context: string;
    entity: string;
    removable: boolean;
    onRemoved: (graph: StructureGraph) => void;
}) {
    const manifest = graph.manifests[context];
    const aggregate = Object.entries(manifest?.aggregates ?? {}).find(
        ([, entry]) => entry?.children.includes(entity),
    )?.[0];

    if (aggregate === undefined) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-2">
            <span className="text-xs text-muted-foreground">
                child of {aggregate}
            </span>
            {removable && manifest?.entities[entity] === undefined && (
                <RemoveButton
                    url={endpoints.removeChild.replace(
                        '__CONTEXT__',
                        encodeURIComponent(context),
                    )}
                    version={graph.versions[context] ?? ''}
                    section="aggregates"
                    name={entity}
                    owner={context}
                    entity={aggregate}
                    onRemoved={onRemoved}
                />
            )}
        </div>
    );
}

/**
 * An aggregate's child entities. A built aggregate keeps its name and repository but takes new
 * children, the way a built entity takes new methods: kit:apply builds each one the code does not
 * have yet, and a child the code already has reads built.
 */
function AggregateChildren({
    graph,
    endpoints,
    context,
    aggregate,
    onChanged,
    onSelect,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    context: string;
    aggregate: string;
    onChanged: (graph: StructureGraph) => void;
    onSelect: (id: string) => void;
}) {
    const children =
        graph.manifests[context]?.aggregates[aggregate]?.children ?? [];
    const built = graph.childrenBuilt[context] ?? [];
    const version = graph.versions[context] ?? '';
    const form = useHttp<
        { version: string; aggregate: string; child: string },
        { graph: StructureGraph }
    >({ version, aggregate, child: '' });

    const add = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            endpoints.addChild.replace(
                '__CONTEXT__',
                encodeURIComponent(context),
            ),
            {
                onSuccess: (response) => {
                    form.setData('child', '');
                    onChanged(response.graph);
                },
            },
        );
    };

    return (
        <div className="space-y-3">
            <div className="text-xs font-medium">Child entities</div>
            {children.length > 0 && (
                <ul className="space-y-2">
                    {children.map((child) => (
                        <li
                            key={child}
                            className="flex items-center justify-between gap-2"
                        >
                            <button
                                type="button"
                                className="font-mono text-xs underline-offset-2 hover:underline"
                                onClick={() =>
                                    onSelect(`entity:${context}/${child}`)
                                }
                            >
                                {child}
                            </button>
                            {built.includes(`${aggregate}.${child}`) ? (
                                <span className="text-xs text-muted-foreground">
                                    built
                                </span>
                            ) : (
                                <RemoveButton
                                    url={endpoints.removeChild.replace(
                                        '__CONTEXT__',
                                        encodeURIComponent(context),
                                    )}
                                    version={version}
                                    section="aggregates"
                                    name={child}
                                    owner={context}
                                    entity={aggregate}
                                    onRemoved={onChanged}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <form onSubmit={add} className="space-y-1">
                <div className="flex gap-2">
                    <Input
                        value={form.data.child}
                        placeholder="Lid"
                        aria-label="New child entity"
                        onChange={(event) =>
                            form.setData('child', event.target.value)
                        }
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={
                            form.processing || form.data.child.trim() === ''
                        }
                    >
                        Add child
                    </Button>
                </div>
                <InputError
                    message={
                        form.errors.child ??
                        form.errors.version ??
                        form.errors.aggregate
                    }
                />
            </form>
        </div>
    );
}

/**
 * An entity's methods, behaviours first, each with its parameters and the exceptions it throws. A
 * method the code does not have yet can be changed or removed, and a new one added.
 */
function EntityMethods({
    graph,
    endpoints,
    context,
    entity,
    onEdit,
    onRemoved,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    context: string;
    entity: string;
    onEdit: (editing: Editing) => void;
    onRemoved: (graph: StructureGraph) => void;
}) {
    const manifest = graph.manifests[context];
    const entry = manifest?.entities[entity];
    const built = graph.entityMethodsBuilt[context] ?? [];
    const outOfStep = graph.outOfStep[context] ?? [];
    const methods = [
        ...Object.keys(entry?.behaviours ?? {}),
        ...Object.keys(entry?.assertions ?? {}),
    ];

    return (
        <div className="space-y-3">
            <ul className="space-y-3">
                {methods.map((method) => {
                    const definition = methodOf(manifest, entity, method);

                    return (
                        <li key={method} className="space-y-1">
                            <div className="font-mono text-xs break-all">
                                {method}(
                                {Object.entries(definition?.params ?? {})
                                    .map(([param, type]) => `${type} $${param}`)
                                    .join(', ')}
                                )
                            </div>
                            {(definition?.throws ?? []).map((exception) => (
                                <div
                                    key={exception}
                                    className="text-xs break-all text-muted-foreground"
                                >
                                    throws {exception}
                                </div>
                            ))}
                            {built.includes(`${entity}.${method}`) ? (
                                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                                    built
                                    {outOfStep.includes(
                                        `entities.${entity}.${method}`,
                                    ) && (
                                        <SyncButton
                                            url={endpoints.syncPiece.replace(
                                                '__CONTEXT__',
                                                encodeURIComponent(context),
                                            )}
                                            version={
                                                graph.versions[context] ?? ''
                                            }
                                            section="entities"
                                            name={method}
                                            entity={entity}
                                            onSynced={onRemoved}
                                        />
                                    )}
                                </div>
                            ) : (
                                <div className="flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            onEdit({
                                                kind: 'method',
                                                context,
                                                entity,
                                                previous: method,
                                            })
                                        }
                                    >
                                        Edit
                                    </Button>
                                    <RemoveButton
                                        url={endpoints.removeMethod.replace(
                                            '__CONTEXT__',
                                            encodeURIComponent(context),
                                        )}
                                        version={graph.versions[context] ?? ''}
                                        section="entities"
                                        name={method}
                                        owner={context}
                                        entity={entity}
                                        onRemoved={onRemoved}
                                    />
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
            <Button
                size="sm"
                variant="outline"
                onClick={() =>
                    onEdit({ kind: 'method', context, entity, previous: null })
                }
            >
                Add method
            </Button>
        </div>
    );
}

function RemoveButton({
    url,
    version,
    section,
    name,
    owner,
    entity = '',
    onRemoved,
}: {
    url: string;
    version: string;
    section: string;
    name: string;
    owner: string;
    entity?: string;
    onRemoved: (graph: StructureGraph) => void;
}) {
    const [confirming, setConfirming] = useState(false);
    const form = useHttp<
        { version: string; section: string; name: string; entity: string },
        { graph: StructureGraph }
    >({ version, section, name, entity });
    const error =
        form.errors.name ??
        form.errors.version ??
        form.errors.section ??
        form.errors.entity;

    const remove = () =>
        form.post(url, {
            onSuccess: (response) => {
                setConfirming(false);
                onRemoved(response.graph);
            },
        });

    return (
        <>
            <Button
                size="sm"
                variant="destructive"
                onClick={() => setConfirming(true)}
            >
                Remove
            </Button>
            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Remove {name}?</DialogTitle>
                        <DialogDescription>
                            It leaves the manifest of {owner}. Git keeps the
                            file as it was.
                        </DialogDescription>
                    </DialogHeader>
                    <InputError message={error} />
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setConfirming(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={form.processing}
                            onClick={remove}
                        >
                            Remove
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function ByHand({ graph }: { graph: StructureGraph }) {
    if (graph.byHand.length === 0) {
        return null;
    }

    return (
        <section className="space-y-2">
            <h2 className="font-medium">By hand ({graph.byHand.length})</h2>
            <p className="text-xs text-muted-foreground">
                Where the code differs from the manifest in a piece the diagram
                does not draw.
            </p>
            <ul className="space-y-2">
                {graph.byHand.map((difference) => (
                    <li
                        key={`${difference.check}:${difference.subject}:${difference.message}`}
                        className="text-xs"
                    >
                        <div className="font-mono break-all">
                            [structure:{difference.check}] {difference.subject}
                        </div>
                        <div className="text-muted-foreground">
                            {difference.message}
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}

/**
 * Takes a built piece back from the code once the code has changed, so the manifest says what the
 * code holds again. What is not built yet stays as designed.
 */
function SyncButton({
    url,
    version,
    section,
    name,
    entity = '',
    onSynced,
}: {
    url: string;
    version: string;
    section: string;
    name: string;
    entity?: string;
    onSynced: (graph: StructureGraph) => void;
}) {
    const form = useHttp<
        { version: string; section: string; name: string; entity: string },
        { graph: StructureGraph }
    >({ version, section, name, entity });
    const error =
        form.errors.name ??
        form.errors.version ??
        form.errors.section ??
        form.errors.entity;

    return (
        <div className="flex flex-col items-start gap-1">
            <Button
                size="sm"
                variant="outline"
                disabled={form.processing}
                onClick={() =>
                    form.post(url, {
                        onSuccess: (response) => onSynced(response.graph),
                    })
                }
            >
                Sync from code
            </Button>
            <InputError message={error} />
        </div>
    );
}
