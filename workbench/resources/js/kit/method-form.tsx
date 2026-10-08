import { useHttp } from '@inertiajs/react';
import { ArrowUp, X } from 'lucide-react';
import { useId } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    ContextManifest,
    EntityMethod,
    StructureEndpoints,
    StructureGraph,
} from '@/kit/types';
import { Field, typeChoices } from '@/kit/vocabulary-form';

/**
 * One parameter of a method: its name and its type.
 */
type Param = { name: string; type: string };

type MethodFormData = {
    context: string;
    version: string;
    entity: string;
    previous: string | null;
    name: string;
    rows: Param[];
    throwRows: string[];
    params: Record<string, string>;
    throws: string[];
};

/**
 * Every entity of a context, by the aggregate that holds it: the root first, then its children.
 */
export function entitiesOf(
    manifest: ContextManifest | undefined,
): { entity: string; aggregate: string }[] {
    return Object.entries(manifest?.aggregates ?? {}).flatMap(
        ([aggregate, entry]) => [
            { entity: aggregate, aggregate },
            ...(entry?.children ?? []).map((child) => ({
                entity: child,
                aggregate,
            })),
        ],
    );
}

/**
 * A method of an entity as the manifest holds it, whichever group it sits in.
 */
export function methodOf(
    manifest: ContextManifest | undefined,
    entity: string,
    method: string,
): EntityMethod | undefined {
    const entry = manifest?.entities[entity];

    return entry?.behaviours[method] ?? entry?.assertions[method];
}

/**
 * The rows as the object and the list the manifest holds, in the order they are listed.
 */
function posted(
    rows: Param[],
    throwRows: string[],
): Pick<MethodFormData, 'params' | 'throws'> {
    return {
        params: Object.fromEntries(
            rows
                .filter((row) => row.name.trim() !== '')
                .map((row) => [row.name.trim(), row.type.trim()]),
        ),
        throws: throwRows
            .map((exception) => exception.trim())
            .filter((exception) => exception !== ''),
    };
}

function startingValues(
    graph: StructureGraph,
    context: string,
    entity: string | null,
    previous: string | null,
): MethodFormData {
    const manifest = graph.manifests[context];
    const method =
        entity !== null && previous !== null
            ? methodOf(manifest, entity, previous)
            : undefined;
    const rows = Object.entries(method?.params ?? {}).map(([name, type]) => ({
        name,
        type,
    }));
    const throwRows = method?.throws ?? [];

    return {
        context,
        version: graph.versions[context] ?? '',
        entity: entity ?? entitiesOf(manifest)[0]?.entity ?? '',
        previous,
        name: previous ?? '',
        rows,
        throwRows,
        ...posted(rows, throwRows),
    };
}

/**
 * The exceptions the aggregate's entities already throw, offered first for a new method.
 */
function exceptionChoices(
    manifest: ContextManifest | undefined,
    aggregate: string | null,
): string[] {
    const choices = new Set<string>();

    for (const entry of Object.values(manifest?.entities ?? {})) {
        if (entry === undefined || entry.aggregate !== aggregate) {
            continue;
        }

        for (const method of [
            ...Object.values(entry.behaviours),
            ...Object.values(entry.assertions),
        ]) {
            for (const exception of method?.throws ?? []) {
                choices.add(exception);
            }
        }
    }

    return [...choices].sort();
}

/**
 * Adds a method to an entity, or changes one the code does not have yet. A name that starts with
 * `assert` makes it an assertion, any other a behaviour. Its parameters are rows in the order the
 * method declares them. An exception of the entity's own aggregate is named bare, and kit:apply
 * builds it when it is missing; one of the shared kernel or of another aggregate is named with its
 * prefix and must exist already. The server checks it all and answers with the graph drawn again.
 */
export function MethodForm({
    graph,
    endpoints,
    context,
    entity,
    previous,
    onSaved,
    onCancel,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    context: string;
    entity: string | null;
    previous: string | null;
    onSaved: (graph: StructureGraph) => void;
    onCancel: () => void;
}) {
    const form = useHttp<MethodFormData, { graph: StructureGraph }>(
        startingValues(graph, context, entity, previous),
    );
    const { data, setData, errors, processing } = form;
    const typesId = useId();
    const exceptionsId = useId();
    const manifest = graph.manifests[context];
    const entities = entitiesOf(manifest);
    const aggregate =
        entities.find((candidate) => candidate.entity === data.entity)
            ?.aggregate ?? null;

    const change = (next: Partial<MethodFormData>) => {
        const merged = { ...data, ...next };

        setData({ ...merged, ...posted(merged.rows, merged.throwRows) });
    };

    const setRow = (index: number, row: Partial<Param>) =>
        change({
            rows: data.rows.map((current, at) =>
                at === index ? { ...current, ...row } : current,
            ),
        });

    const moveUp = (index: number) =>
        change({
            rows: data.rows.map((current, at) =>
                at === index - 1
                    ? data.rows[index]
                    : at === index
                      ? data.rows[index - 1]
                      : current,
            ),
        });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            endpoints.saveMethod.replace(
                '__CONTEXT__',
                encodeURIComponent(context),
            ),
            { onSuccess: (response) => onSaved(response.graph) },
        );
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <h2 className="font-medium">
                {previous === null
                    ? `Add a method to ${entity ?? context}`
                    : `Change ${entity}::${previous}()`}
            </h2>
            <InputError message={errors.version ?? errors.context} />

            <Field label="Entity" error={errors.entity}>
                <Select
                    value={data.entity}
                    disabled={entity !== null}
                    onValueChange={(next) => change({ entity: next })}
                >
                    <SelectTrigger className="w-full">
                        <SelectValue placeholder="Pick its entity" />
                    </SelectTrigger>
                    <SelectContent>
                        {entities.map((candidate) => (
                            <SelectItem
                                key={candidate.entity}
                                value={candidate.entity}
                            >
                                {candidate.entity === candidate.aggregate
                                    ? candidate.entity
                                    : `${candidate.aggregate} / ${candidate.entity}`}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>

            <Field label="Name" error={errors.name}>
                <Input
                    value={data.name}
                    placeholder="suspend, or assertIsOpen for an assertion"
                    onChange={(event) => change({ name: event.target.value })}
                    autoFocus
                />
            </Field>

            <Field label="Parameters, in order" error={errors.params}>
                <datalist id={typesId}>
                    {typeChoices(graph, context, aggregate).map((type) => (
                        <option key={type} value={type} />
                    ))}
                </datalist>
                <div className="space-y-2">
                    {data.rows.map((row, index) => (
                        <div key={index} className="flex items-center gap-1.5">
                            <Input
                                value={row.name}
                                placeholder="parameter"
                                onChange={(event) =>
                                    setRow(index, { name: event.target.value })
                                }
                            />
                            <Input
                                value={row.type}
                                list={typesId}
                                placeholder="Type, or ...Type last"
                                onChange={(event) =>
                                    setRow(index, { type: event.target.value })
                                }
                            />
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="shrink-0"
                                disabled={index === 0}
                                aria-label="Move up"
                                onClick={() => moveUp(index)}
                            >
                                <ArrowUp />
                            </Button>
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="shrink-0"
                                aria-label="Remove"
                                onClick={() =>
                                    change({
                                        rows: data.rows.filter(
                                            (_, at) => at !== index,
                                        ),
                                    })
                                }
                            >
                                <X />
                            </Button>
                        </div>
                    ))}
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            change({
                                rows: [...data.rows, { name: '', type: '' }],
                            })
                        }
                    >
                        Add a parameter
                    </Button>
                </div>
            </Field>

            <Field label="Throws" error={errors.throws}>
                <datalist id={exceptionsId}>
                    {exceptionChoices(manifest, aggregate).map((exception) => (
                        <option key={exception} value={exception} />
                    ))}
                </datalist>
                <div className="space-y-2">
                    {data.throwRows.map((exception, index) => (
                        <div key={index} className="flex items-center gap-1.5">
                            <Input
                                value={exception}
                                list={exceptionsId}
                                placeholder="SomethingRefusedException"
                                onChange={(event) =>
                                    change({
                                        throwRows: data.throwRows.map(
                                            (current, at) =>
                                                at === index
                                                    ? event.target.value
                                                    : current,
                                        ),
                                    })
                                }
                            />
                            <Button
                                type="button"
                                size="icon"
                                variant="ghost"
                                className="shrink-0"
                                aria-label="Remove"
                                onClick={() =>
                                    change({
                                        throwRows: data.throwRows.filter(
                                            (_, at) => at !== index,
                                        ),
                                    })
                                }
                            >
                                <X />
                            </Button>
                        </div>
                    ))}
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            change({ throwRows: [...data.throwRows, ''] })
                        }
                    >
                        Add an exception
                    </Button>
                </div>
            </Field>

            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={processing}>
                    {previous === null ? 'Add' : 'Save'}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onCancel}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}
