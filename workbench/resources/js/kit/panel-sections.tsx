import { useHttp } from '@inertiajs/react';
import { CircleCheck, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { methodOf } from '@/kit/method-form';
import {
    IconAction,
    PanelRow,
    PanelSection,
    PanelTable,
} from '@/kit/panel-table';
import type {
    MethodHolder,
    ReplaceableSection,
    ResourceSection,
    StructureEndpoints,
    StructureGraph,
    StructureSection,
} from '@/kit/types';

/**
 * What the side panel edits: a piece of the context or resource open now, the resource's
 * settings, or a new context or resource.
 */
export type Editing =
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
          holder?: MethodHolder;
          entity: string | null;
          previous: string | null;
      }
    | {
          kind: 'state';
          context: string;
          entity: string;
          previous: string | null;
      };

/**
 * What a change answers with: the graph drawn again, and a line that says what happened.
 */
export type Changed = (graph: StructureGraph, message: string) => void;

type Posted = {
    version: string;
    section: string;
    name: string;
    entity: string;
    holder?: string;
};

/**
 * The url of an endpoint for the context or resource it acts on.
 */
export function endpointFor(url: string, owner: string): string {
    return url
        .replace('__CONTEXT__', encodeURIComponent(owner))
        .replace('__RESOURCE__', encodeURIComponent(owner));
}

/**
 * The first fault the server named, for a toast.
 */
function firstError(errors: Partial<Record<string, string>>): string {
    return Object.values(errors).find((error) => error !== undefined) ?? '';
}

/**
 * A mark that the code already has what the row names, with what else there is to know about it.
 */
export function BuiltMark({ detail }: { detail?: string }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span
                    className="inline-flex size-7 items-center justify-center text-emerald-600 dark:text-emerald-400"
                    aria-label={
                        detail === undefined ? 'built' : `built, ${detail}`
                    }
                >
                    <CircleCheck className="size-3.5" />
                </span>
            </TooltipTrigger>
            <TooltipContent>
                {detail === undefined
                    ? 'Built: the code already has it'
                    : `Built: ${detail}`}
            </TooltipContent>
        </Tooltip>
    );
}

/**
 * Removes a piece the code does not have yet, after asking. The manifest's version is read when it
 * posts, so a panel that stays open across changes never sends a stale one.
 */
export function RemoveButton({
    url,
    version,
    section,
    name,
    owner,
    entity = '',
    label = `Remove ${name}`,
    onRemoved,
}: {
    url: string;
    version: string;
    section: string;
    name: string;
    owner: string;
    entity?: string;
    label?: string;
    onRemoved: Changed;
}) {
    const [confirming, setConfirming] = useState(false);
    const form = useHttp<Posted, { graph: StructureGraph }>({
        version,
        section,
        name,
        entity,
        holder: section,
    });

    const remove = () => {
        form.transform((data) => ({
            ...data,
            version,
            section,
            name,
            entity,
            holder: section,
        }));
        form.post(url, {
            onSuccess: (response) => {
                setConfirming(false);
                onRemoved(response.graph, `Removed ${name}`);
            },
        });
    };

    return (
        <>
            <IconAction
                icon={Trash2}
                label={label}
                tone="danger"
                onClick={() => setConfirming(true)}
            />
            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Remove {name}?</DialogTitle>
                        <DialogDescription>
                            It leaves the manifest of {owner}. Git keeps the
                            file as it was.
                        </DialogDescription>
                    </DialogHeader>
                    <InputError
                        message={firstError(form.errors) || undefined}
                    />
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

/**
 * Takes a built piece back from the code once the code has changed, so the manifest says what the
 * code holds again. What is not built yet stays as designed. A refusal shows as a toast.
 */
export function SyncButton({
    url,
    version,
    section,
    name,
    entity = '',
    label = 'Sync from code',
    onSynced,
}: {
    url: string;
    version: string;
    section: string;
    name: string;
    entity?: string;
    label?: string;
    onSynced: Changed;
}) {
    const form = useHttp<Posted, { graph: StructureGraph }>({
        version,
        section,
        name,
        entity,
    });

    const sync = () => {
        form.transform((data) => ({ ...data, version, section, name, entity }));
        form.post(url, {
            onSuccess: (response) =>
                onSynced(
                    response.graph,
                    `Synced ${name.replace(/^state\./, '') || section} from the code`,
                ),
            onError: (errors) => {
                toast.error(firstError(errors) || 'The sync was refused.');
            },
        });
    };

    return (
        <IconAction
            icon={RefreshCw}
            label={label}
            disabled={form.processing}
            className="text-amber-600 dark:text-amber-400"
            onClick={sync}
        />
    );
}

/**
 * A controller's methods, one row each, with the use cases it calls. A method the code does not
 * have yet can be changed or removed.
 */
export function ControllerMethods({
    graph,
    endpoints,
    resource,
    onEdit,
    onChanged,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    resource: string;
    onEdit: (editing: Editing) => void;
    onChanged: Changed;
}) {
    const controller = graph.resourceManifests[resource]?.controller ?? {};
    const built = graph.resourceBuilt[resource] ?? [];
    const outOfStep = graph.resourceOutOfStep[resource] ?? [];
    const version = graph.resourceVersions[resource] ?? '';

    return (
        <PanelSection
            title="Methods"
            actions={
                <IconAction
                    icon={Plus}
                    label="Add method"
                    onClick={() =>
                        onEdit({
                            kind: 'resource-piece',
                            resource,
                            section: 'controller',
                            previous: null,
                        })
                    }
                />
            }
        >
            <PanelTable
                head={['Method', 'Use cases', '']}
                empty="No methods yet."
            >
                {Object.entries(controller).map(([method, useCases]) => (
                    <PanelRow
                        key={method}
                        cells={[
                            `${method}()`,
                            <span key="calls" className="text-muted-foreground">
                                {(useCases ?? []).join(', ') || '—'}
                            </span>,
                        ]}
                        actions={
                            built.includes(`controller.${method}`) ? (
                                <>
                                    {outOfStep.includes(
                                        `controller.${method}`,
                                    ) && (
                                        <SyncButton
                                            url={endpointFor(
                                                endpoints.syncResourcePiece,
                                                resource,
                                            )}
                                            version={version}
                                            section="controller"
                                            name={method}
                                            label={`Sync ${method}() from code`}
                                            onSynced={onChanged}
                                        />
                                    )}
                                    <BuiltMark />
                                </>
                            ) : (
                                <>
                                    <IconAction
                                        icon={Pencil}
                                        label={`Edit ${method}()`}
                                        onClick={() =>
                                            onEdit({
                                                kind: 'resource-piece',
                                                resource,
                                                section: 'controller',
                                                previous: method,
                                            })
                                        }
                                    />
                                    <RemoveButton
                                        url={endpointFor(
                                            endpoints.removeResourcePiece,
                                            resource,
                                        )}
                                        version={version}
                                        section="controller"
                                        name={method}
                                        owner={resource}
                                        onRemoved={onChanged}
                                    />
                                </>
                            )
                        }
                    />
                ))}
            </PanelTable>
        </PanelSection>
    );
}

/**
 * Which aggregate a child entity belongs to, and its Remove while the code does not have it yet
 * and it lists nothing. A root says nothing here: its aggregate's card holds it.
 */
export function ChildOf({
    graph,
    endpoints,
    context,
    entity,
    removable,
    onSelect,
    onRemoved,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    context: string;
    entity: string;
    removable: boolean;
    onSelect: (id: string) => void;
    onRemoved: Changed;
}) {
    const manifest = graph.manifests[context];
    const aggregate = Object.entries(manifest?.aggregates ?? {}).find(
        ([, entry]) => entry?.children.includes(entity),
    )?.[0];

    if (aggregate === undefined) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
            <span>
                child of{' '}
                <button
                    type="button"
                    className="font-mono text-foreground underline-offset-2 hover:underline"
                    onClick={() =>
                        onSelect(`aggregate:${context}/${aggregate}`)
                    }
                >
                    {aggregate}
                </button>
            </span>
            {removable && manifest?.entities[entity] === undefined && (
                <RemoveButton
                    url={endpointFor(endpoints.removeChild, context)}
                    version={graph.versions[context] ?? ''}
                    section="aggregates"
                    name={entity}
                    owner={context}
                    entity={aggregate}
                    label={`Remove the child ${entity}`}
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
export function AggregateChildren({
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
    onChanged: Changed;
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
        const child = form.data.child.trim();

        form.transform((data) => ({ ...data, version, aggregate }));
        form.post(endpointFor(endpoints.addChild, context), {
            onSuccess: (response) => {
                form.setData('child', '');
                onChanged(response.graph, `Added the child ${child}`);
            },
        });
    };

    return (
        <PanelSection title="Child entities">
            <PanelTable head={['Child', '']} empty="No children yet.">
                {children.map((child) => (
                    <PanelRow
                        key={child}
                        cells={[
                            <button
                                key="name"
                                type="button"
                                className="underline-offset-2 hover:underline"
                                onClick={() =>
                                    onSelect(`entity:${context}/${child}`)
                                }
                            >
                                {child}
                            </button>,
                        ]}
                        actions={
                            built.includes(`${aggregate}.${child}`) ? (
                                <BuiltMark />
                            ) : (
                                <RemoveButton
                                    url={endpointFor(
                                        endpoints.removeChild,
                                        context,
                                    )}
                                    version={version}
                                    section="aggregates"
                                    name={child}
                                    owner={context}
                                    entity={aggregate}
                                    onRemoved={onChanged}
                                />
                            )
                        }
                    />
                ))}
            </PanelTable>
            <form onSubmit={add} className="space-y-1">
                <div className="flex items-center gap-1">
                    <Input
                        value={form.data.child}
                        placeholder="New child, e.g. Lid"
                        aria-label="New child entity"
                        className="h-8 text-xs"
                        onChange={(event) =>
                            form.setData('child', event.target.value)
                        }
                    />
                    <IconAction
                        icon={Plus}
                        label="Add child"
                        type="submit"
                        disabled={
                            form.processing || form.data.child.trim() === ''
                        }
                    />
                </div>
                <InputError
                    message={
                        form.errors.child ??
                        form.errors.version ??
                        form.errors.aggregate
                    }
                />
            </form>
        </PanelSection>
    );
}

/**
 * An entity's state, each property with its type in the constructor's order. A property the code
 * does not have yet can be changed or removed, and a new one added; a built one shows its getter.
 */
export function EntityState({
    graph,
    endpoints,
    context,
    entity,
    onEdit,
    onChanged,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    context: string;
    entity: string;
    onEdit: (editing: Editing) => void;
    onChanged: Changed;
}) {
    const state = graph.manifests[context]?.entities[entity]?.state ?? {};
    const built = graph.entityStateBuilt[context] ?? [];
    const outOfStep = graph.outOfStep[context] ?? [];
    const version = graph.versions[context] ?? '';

    return (
        <PanelSection
            title="State"
            actions={
                <IconAction
                    icon={Plus}
                    label="Add state"
                    onClick={() =>
                        onEdit({
                            kind: 'state',
                            context,
                            entity,
                            previous: null,
                        })
                    }
                />
            }
        >
            <PanelTable head={['Property', 'Type', '']} empty="No state yet.">
                {Object.entries(state).map(([property, type]) => (
                    <PanelRow
                        key={property}
                        cells={[property, type]}
                        actions={
                            built.includes(`${entity}.${property}`) ? (
                                <>
                                    {outOfStep.includes(
                                        `entities.${entity}.state.${property}`,
                                    ) && (
                                        <SyncButton
                                            url={endpointFor(
                                                endpoints.syncPiece,
                                                context,
                                            )}
                                            version={version}
                                            section="entities"
                                            name={`state.${property}`}
                                            entity={entity}
                                            label={`Sync ${property} from code`}
                                            onSynced={onChanged}
                                        />
                                    )}
                                    <BuiltMark
                                        detail={`read by ${property}()`}
                                    />
                                </>
                            ) : (
                                <>
                                    <IconAction
                                        icon={Pencil}
                                        label={`Edit ${property}`}
                                        onClick={() =>
                                            onEdit({
                                                kind: 'state',
                                                context,
                                                entity,
                                                previous: property,
                                            })
                                        }
                                    />
                                    <RemoveButton
                                        url={endpointFor(
                                            endpoints.removeState,
                                            context,
                                        )}
                                        version={version}
                                        section="entities"
                                        name={property}
                                        owner={context}
                                        entity={entity}
                                        onRemoved={onChanged}
                                    />
                                </>
                            )
                        }
                    />
                ))}
            </PanelTable>
        </PanelSection>
    );
}

/**
 * An entity's or a value object's methods, behaviours first, each with its parameters and the
 * exceptions it throws. A method the code does not have yet can be changed or removed, and a new
 * one added, though the entity or the value object is built.
 */
export function EntityMethods({
    graph,
    endpoints,
    context,
    holder = 'entities',
    entity,
    onEdit,
    onChanged,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    context: string;
    holder?: MethodHolder;
    entity: string;
    onEdit: (editing: Editing) => void;
    onChanged: Changed;
}) {
    const manifest = graph.manifests[context];
    const entry = manifest?.[holder][entity];
    const built =
        (holder === 'entities'
            ? graph.entityMethodsBuilt[context]
            : graph.valueObjectMethodsBuilt[context]) ?? [];
    const outOfStep = graph.outOfStep[context] ?? [];
    const version = graph.versions[context] ?? '';
    const methods = [
        ...Object.keys(entry?.behaviours ?? {}),
        ...Object.keys(entry?.assertions ?? {}),
    ];

    return (
        <PanelSection
            title="Methods"
            actions={
                <IconAction
                    icon={Plus}
                    label="Add method"
                    onClick={() =>
                        onEdit({
                            kind: 'method',
                            context,
                            holder,
                            entity,
                            previous: null,
                        })
                    }
                />
            }
        >
            <PanelTable head={['Method', 'Throws', '']} empty="No methods yet.">
                {methods.map((method) => {
                    const definition = methodOf(
                        manifest,
                        entity,
                        method,
                        holder,
                    );
                    const params = Object.entries(definition?.params ?? {})
                        .map(([param, type]) => `${type} $${param}`)
                        .join(', ');

                    return (
                        <PanelRow
                            key={method}
                            cells={[
                                `${method}(${params})`,
                                <span
                                    key="throws"
                                    className="text-muted-foreground"
                                >
                                    {(definition?.throws ?? []).join(', ') ||
                                        '—'}
                                </span>,
                            ]}
                            actions={
                                built.includes(`${entity}.${method}`) ? (
                                    <>
                                        {outOfStep.includes(
                                            `${holder}.${entity}.${method}`,
                                        ) && (
                                            <SyncButton
                                                url={endpointFor(
                                                    endpoints.syncPiece,
                                                    context,
                                                )}
                                                version={version}
                                                section={holder}
                                                name={method}
                                                entity={entity}
                                                label={`Sync ${method}() from code`}
                                                onSynced={onChanged}
                                            />
                                        )}
                                        <BuiltMark />
                                    </>
                                ) : (
                                    <>
                                        <IconAction
                                            icon={Pencil}
                                            label={`Edit ${method}()`}
                                            onClick={() =>
                                                onEdit({
                                                    kind: 'method',
                                                    context,
                                                    holder,
                                                    entity,
                                                    previous: method,
                                                })
                                            }
                                        />
                                        <RemoveButton
                                            url={endpointFor(
                                                endpoints.removeMethod,
                                                context,
                                            )}
                                            version={version}
                                            section={holder}
                                            name={method}
                                            owner={context}
                                            entity={entity}
                                            onRemoved={onChanged}
                                        />
                                    </>
                                )
                            }
                        />
                    );
                })}
            </PanelTable>
        </PanelSection>
    );
}

/**
 * Where the code differs from the manifest in a piece the diagram does not draw.
 */
export function ByHand({ graph }: { graph: StructureGraph }) {
    if (graph.byHand.length === 0) {
        return null;
    }

    return (
        <PanelSection title={`By hand (${graph.byHand.length})`}>
            <p className="text-xs text-muted-foreground">
                Where the code differs from the manifest in a piece the diagram
                does not draw.
            </p>
            <PanelTable head={['Check', 'Subject', 'What differs']} empty="">
                {graph.byHand.map((difference) => (
                    <PanelRow
                        key={`${difference.check}:${difference.subject}:${difference.message}`}
                        cells={[
                            difference.check,
                            difference.subject,
                            <span
                                key="message"
                                className="font-sans text-muted-foreground"
                            >
                                {difference.message}
                            </span>,
                        ]}
                    />
                ))}
            </PanelTable>
        </PanelSection>
    );
}
