import { useHttp } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    StructureEndpoints,
    StructureGraph,
    StructureSection,
} from '@/kit/types';

export const SECTION_LABELS: Record<StructureSection, string> = {
    aggregates: 'aggregate',
    services: 'domain service',
    ports: 'port',
    useCases: 'use case',
    enums: 'enum',
    valueObjects: 'value object',
    exceptions: 'exception',
};

/**
 * Every field any section posts, flat, so an error the editor names by field lands under it.
 */
type PieceFormData = {
    context: string;
    version: string;
    section: StructureSection;
    previous: string | null;
    name: string;
    children: string[];
    repository: boolean;
    shape: string;
    creates: string | boolean | null;
    repositories: string[];
    layer: string;
    adapter: string;
    returns: string;
    query: boolean;
    exception: boolean;
};

function startingValues(
    graph: StructureGraph,
    context: string,
    section: StructureSection,
    previous: string | null,
): PieceFormData {
    const manifest = graph.manifests[context];
    const base: PieceFormData = {
        context,
        version: graph.versions[context] ?? '',
        section,
        previous,
        name: previous ?? '',
        children: [],
        repository: true,
        shape: {
            aggregates: '',
            services: 'plain',
            ports: '',
            useCases: 'command',
            enums: '',
            valueObjects: '',
            exceptions: '',
        }[section],
        creates: section === 'useCases' ? false : null,
        repositories: [],
        layer: 'domain',
        adapter: '',
        returns: 'void',
        query: false,
        exception: false,
    };

    if (previous === null || manifest === undefined) {
        return base;
    }

    const entry = manifest[section][previous];

    return entry === undefined
        ? base
        : {
              ...base,
              ...entry,
              adapter: 'adapter' in entry ? (entry.adapter ?? '') : '',
          };
}

/**
 * The aggregates a piece may name: those of its own context, or every context's when it may reach
 * across, as `Aggregate` inside the context and `Context/Aggregate` outside it.
 */
function aggregateChoices(
    graph: StructureGraph,
    context: string,
    acrossContexts: boolean,
    withRepository: boolean,
): { group: string; values: string[] }[] {
    return Object.entries(graph.manifests)
        .filter(([name]) => acrossContexts || name === context)
        .map(([name, manifest]) => ({
            group: name,
            values: Object.entries(manifest?.aggregates ?? {})
                .filter(
                    ([, aggregate]) =>
                        !withRepository || aggregate?.repository === true,
                )
                .map(([aggregate]) =>
                    name === context ? aggregate : `${name}/${aggregate}`,
                ),
        }))
        .filter((choice) => choice.values.length > 0)
        .sort((a, b) =>
            a.group === context
                ? -1
                : b.group === context
                  ? 1
                  : a.group.localeCompare(b.group),
        );
}

function Field({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <Label>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

function Choice({
    value,
    onChange,
    options,
}: {
    value: string;
    onChange: (value: string) => void;
    options: string[];
}) {
    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger className="w-full">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {options.map((option) => (
                    <SelectItem key={option} value={option}>
                        {option}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * Adds a piece to a context, or changes one the code does not have yet. The server checks the
 * manifest's shape and its design rules and answers with the graph drawn again.
 */
export function PieceForm({
    graph,
    endpoints,
    context,
    section,
    previous,
    onSaved,
    onCancel,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    context: string;
    section: StructureSection;
    previous: string | null;
    onSaved: (graph: StructureGraph, name: string) => void;
    onCancel: () => void;
}) {
    const form = useHttp<PieceFormData, { graph: StructureGraph }>(
        startingValues(graph, context, section, previous),
    );
    const { data, setData, errors, processing } = form;

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

    const toggle = (value: string, checked: boolean) =>
        setData(
            'repositories',
            checked
                ? [...data.repositories, value]
                : data.repositories.filter((item) => item !== value),
        );

    const repositoryChoices = aggregateChoices(
        graph,
        context,
        section === 'useCases',
        true,
    );

    return (
        <form onSubmit={submit} className="space-y-4">
            <h2 className="font-medium">
                {previous === null
                    ? `Add a ${SECTION_LABELS[section]} to ${context}`
                    : `Change ${previous}`}
            </h2>
            <InputError
                message={errors.version ?? errors.context ?? errors.section}
            />

            <Field label="Name" error={errors.name}>
                <Input
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    autoFocus
                />
            </Field>

            {section === 'aggregates' && (
                <>
                    <Field
                        label="Child entities, separated by commas"
                        error={errors.children}
                    >
                        <Input
                            defaultValue={data.children.join(', ')}
                            onChange={(event) =>
                                setData(
                                    'children',
                                    event.target.value
                                        .split(',')
                                        .map((child) => child.trim())
                                        .filter((child) => child !== ''),
                                )
                            }
                        />
                    </Field>
                    <Field label="Repository" error={errors.repository}>
                        <Label className="flex items-center gap-2 font-normal">
                            <Checkbox
                                checked={data.repository}
                                onCheckedChange={(checked) =>
                                    setData('repository', checked === true)
                                }
                            />
                            It is saved through {data.name || 'its'}Repository
                        </Label>
                    </Field>
                </>
            )}

            {section === 'services' && (
                <>
                    <Field label="Shape of handle()" error={errors.shape}>
                        <Choice
                            value={data.shape}
                            onChange={(shape) => setData('shape', shape)}
                            options={['creates', 'data', 'plain']}
                        />
                    </Field>
                    <Field label="Exception" error={errors.exception}>
                        <Label className="flex items-center gap-2 font-normal">
                            <Checkbox
                                checked={data.exception}
                                onCheckedChange={(checked) =>
                                    setData('exception', checked === true)
                                }
                            />
                            It has its own exception,{' '}
                            {`${data.name.trim() || '{Name}'}Exception`}
                        </Label>
                    </Field>
                    {data.shape === 'creates' && (
                        <Field
                            label="Builds the aggregate"
                            error={errors.creates}
                        >
                            <Choice
                                value={
                                    typeof data.creates === 'string'
                                        ? data.creates
                                        : ''
                                }
                                onChange={(creates) =>
                                    setData('creates', creates)
                                }
                                options={Object.keys(
                                    graph.manifests[context]?.aggregates ?? {},
                                )}
                            />
                        </Field>
                    )}
                </>
            )}

            {section === 'ports' && (
                <>
                    <Field label="Layer" error={errors.layer}>
                        <Choice
                            value={data.layer}
                            onChange={(layer) => setData('layer', layer)}
                            options={['domain', 'application']}
                        />
                    </Field>
                    <Field
                        label={`Adapter, as Infra/{Folder}/{Prefix}${data.name || 'Port'} (optional)`}
                        error={errors.adapter}
                    >
                        <Input
                            value={data.adapter}
                            onChange={(event) =>
                                setData('adapter', event.target.value)
                            }
                        />
                    </Field>
                </>
            )}

            {section === 'useCases' && (
                <>
                    <Field label="Shape of __invoke()" error={errors.shape}>
                        <Choice
                            value={data.shape}
                            onChange={(shape) =>
                                setData({
                                    ...data,
                                    shape,
                                    returns:
                                        shape === 'command-result'
                                            ? 'result'
                                            : 'void',
                                })
                            }
                            options={['command-result', 'command', 'plain']}
                        />
                    </Field>
                    <Field label="Returns" error={errors.returns}>
                        {data.shape === 'plain' ? (
                            <Input
                                value={data.returns}
                                onChange={(event) =>
                                    setData('returns', event.target.value)
                                }
                            />
                        ) : (
                            /*
                             * Keyed on the shape: Radix Select clears its value when the options
                             * change under it, which would post an empty return type.
                             */
                            <Choice
                                key={data.shape}
                                value={data.returns}
                                onChange={(returns) =>
                                    setData('returns', returns)
                                }
                                options={
                                    data.shape === 'command-result'
                                        ? ['result']
                                        : ['void', 'string', 'int']
                                }
                            />
                        )}
                    </Field>
                    <Field
                        label="Options"
                        error={errors.creates ?? errors.query}
                    >
                        <Label className="flex items-center gap-2 font-normal">
                            <Checkbox
                                checked={data.creates === true}
                                onCheckedChange={(checked) =>
                                    setData('creates', checked === true)
                                }
                            />
                            Mints ids through IdGenerator
                        </Label>
                        <Label className="flex items-center gap-2 font-normal">
                            <Checkbox
                                checked={data.query}
                                onCheckedChange={(checked) =>
                                    setData('query', checked === true)
                                }
                            />
                            Reads a list through a query port
                        </Label>
                    </Field>
                </>
            )}

            {(section === 'services' || section === 'useCases') && (
                <Field
                    label="Repositories it injects"
                    error={errors.repositories}
                >
                    {repositoryChoices.length === 0 && (
                        <p className="text-xs text-muted-foreground">
                            No aggregate has a repository yet.
                        </p>
                    )}
                    {repositoryChoices.map((choice) => (
                        <div key={choice.group} className="space-y-1">
                            <div className="text-xs text-muted-foreground">
                                {choice.group}
                            </div>
                            {choice.values.map((value) => (
                                <Label
                                    key={value}
                                    className="flex items-center gap-2 font-normal"
                                >
                                    <Checkbox
                                        checked={data.repositories.includes(
                                            value,
                                        )}
                                        onCheckedChange={(checked) =>
                                            toggle(value, checked === true)
                                        }
                                    />
                                    {value}
                                </Label>
                            ))}
                        </div>
                    ))}
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
