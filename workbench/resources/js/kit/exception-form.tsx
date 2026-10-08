import { useHttp } from '@inertiajs/react';
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
    ExceptionKind,
    StructureEndpoints,
    StructureGraph,
} from '@/kit/types';
import { Field } from '@/kit/vocabulary-form';

/**
 * The shared kernel, which holds invalid values only.
 */
const SHARED = 'Shared';

/**
 * The value the use case select holds when the refusal belongs to the whole context. Radix keeps
 * an empty string for "nothing picked", so it cannot stand for a choice.
 */
const NO_USE_CASE = '__none__';

const KIND_LABELS: Record<ExceptionKind, string> = {
    refusal: 'Refusal of an aggregate',
    value: 'Invalid value',
    application: 'Refusal of a use case',
};

type ExceptionFormData = {
    context: string;
    version: string;
    section: 'exceptions';
    previous: string | null;
    name: string;
    kind: ExceptionKind;
    aggregate: string | null;
    useCase: string | null;
};

function startingValues(
    graph: StructureGraph,
    context: string,
    previous: string | null,
): ExceptionFormData {
    const manifest = graph.manifests[context];
    const firstAggregate = Object.keys(manifest?.aggregates ?? {})[0] ?? null;
    const entry =
        previous === null ? undefined : manifest?.exceptions[previous];
    const kind: ExceptionKind =
        entry?.kind ?? (context === SHARED ? 'value' : 'refusal');

    return {
        context,
        version: graph.versions[context] ?? '',
        section: 'exceptions',
        previous,
        name: previous ?? '',
        kind,
        aggregate:
            entry !== undefined
                ? entry.aggregate
                : kind === 'application' || context === SHARED
                  ? null
                  : firstAggregate,
        useCase: entry?.useCase ?? null,
    };
}

/**
 * Adds an exception to a context, or changes one the code does not have yet: its name, its kind
 * and where it lives. A refusal or an invalid value sits in an aggregate's `Exceptions/`, a use
 * case's refusal beside the use case or at the root of the context's application. Its named
 * constructors are written by hand, like a method's body. The server checks the design rules and
 * answers with the graph drawn again.
 */
export function ExceptionForm({
    graph,
    endpoints,
    context,
    previous,
    onSaved,
    onCancel,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    context: string;
    previous: string | null;
    onSaved: (graph: StructureGraph, name: string) => void;
    onCancel: () => void;
}) {
    const form = useHttp<ExceptionFormData, { graph: StructureGraph }>(
        startingValues(graph, context, previous),
    );
    const { data, setData, errors, processing } = form;
    const manifest = graph.manifests[context];
    const aggregates = Object.keys(manifest?.aggregates ?? {});
    const useCases = Object.keys(manifest?.useCases ?? {});
    const isShared = context === SHARED;

    const change = (next: Partial<ExceptionFormData>) =>
        setData({ ...data, ...next });

    const changeKind = (kind: ExceptionKind) =>
        change({
            kind,
            aggregate:
                kind === 'application'
                    ? null
                    : (data.aggregate ?? aggregates[0] ?? null),
            useCase: kind === 'application' ? data.useCase : null,
        });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            endpoints.savePiece.replace(
                '__CONTEXT__',
                encodeURIComponent(context),
            ),
            {
                onSuccess: (response) => onSaved(response.graph, data.name),
            },
        );
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <h2 className="font-medium">
                {previous === null
                    ? `Add an exception to ${context}`
                    : `Change ${previous}`}
            </h2>
            <InputError
                message={errors.version ?? errors.context ?? errors.section}
            />

            <Field label="Name" error={errors.name}>
                <Input
                    value={data.name}
                    placeholder="CrateSealedException"
                    onChange={(event) => change({ name: event.target.value })}
                    autoFocus
                />
            </Field>

            {isShared ? (
                <p className="text-xs text-muted-foreground">
                    The shared kernel holds invalid values only, for its value
                    objects (exceptions.md).
                </p>
            ) : (
                <Field label="Kind" error={errors.kind}>
                    <Select
                        value={data.kind}
                        onValueChange={(kind) =>
                            changeKind(kind as ExceptionKind)
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(Object.keys(KIND_LABELS) as ExceptionKind[]).map(
                                (kind) => (
                                    <SelectItem key={kind} value={kind}>
                                        {KIND_LABELS[kind]}
                                    </SelectItem>
                                ),
                            )}
                        </SelectContent>
                    </Select>
                </Field>
            )}

            {!isShared && data.kind !== 'application' && (
                <Field label="Aggregate" error={errors.aggregate}>
                    <Select
                        value={data.aggregate ?? ''}
                        onValueChange={(aggregate) => change({ aggregate })}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue placeholder="Pick its aggregate" />
                        </SelectTrigger>
                        <SelectContent>
                            {aggregates.map((aggregate) => (
                                <SelectItem key={aggregate} value={aggregate}>
                                    {aggregate}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            )}

            {data.kind === 'application' && (
                <Field label="Use case" error={errors.useCase}>
                    <Select
                        value={data.useCase ?? NO_USE_CASE}
                        onValueChange={(useCase) =>
                            change({
                                useCase:
                                    useCase === NO_USE_CASE ? null : useCase,
                            })
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NO_USE_CASE}>
                                None: shared by the context's use cases
                            </SelectItem>
                            {useCases.map((useCase) => (
                                <SelectItem key={useCase} value={useCase}>
                                    {useCase}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            )}

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
