import { useHttp } from '@inertiajs/react';
import { useState } from 'react';
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
    applicationServices: 'application service',
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
    actor: boolean;
    exception: boolean;
};

type UseCaseKind =
    'custom' | 'create' | 'change' | 'delete' | 'bulk' | 'list' | 'show';

type UseCaseKindPreset = {
    label: string;
    hint: string;
    /** What the name starts with, before the aggregate; `null` asks the user for a verb. */
    prefix: string | null;
    /** Whether the aggregate goes into the name in the plural, as a list's does. */
    plural: boolean;
    shape: string;
    returns: string;
    creates: boolean;
    query: boolean;
    /** Whether it injects the aggregate's repository: a list and a read inject none. */
    writes: boolean;
};

/**
 * The kinds of use case most pages call, each one fixing the fields the guidelines ask of it
 * (handlers.md, list-queries.md, actions.md), so its form asks only for what is left. Custom
 * keeps every field, and starts from what the last kind filled in.
 */
const USE_CASE_KINDS: Record<
    Exclude<UseCaseKind, 'custom'>,
    UseCaseKindPreset
> = {
    create: {
        label: 'Create',
        hint: 'Builds a new root from a form and returns its id, for a store method.',
        prefix: 'Create',
        plural: false,
        shape: 'command',
        returns: 'string',
        creates: true,
        query: false,
        writes: true,
    },
    change: {
        label: 'Change',
        hint: 'Changes one root from a form, for an update method.',
        prefix: 'Update',
        plural: false,
        shape: 'command',
        returns: 'void',
        creates: false,
        query: false,
        writes: true,
    },
    delete: {
        label: 'Delete',
        hint: 'Removes one root by its id, for a destroy method.',
        prefix: 'Delete',
        plural: false,
        shape: 'plain',
        returns: 'void',
        creates: false,
        query: false,
        writes: true,
    },
    bulk: {
        label: 'Bulk action',
        hint: 'Acts on the ids of a selection and returns how many rows it changed, for a bulk action.',
        prefix: null,
        plural: true,
        shape: 'command',
        returns: 'int',
        creates: false,
        query: false,
        writes: true,
    },
    list: {
        label: 'List',
        hint: 'Reads a page of rows through a query port, for an index method.',
        prefix: 'List',
        plural: true,
        shape: 'command-result',
        returns: 'result',
        creates: false,
        query: true,
        writes: false,
    },
    show: {
        label: 'Show',
        hint: 'Reads what one page shows, such as a detail or a profile, into a read class of its own. A plain show page reads its model in the controller and needs no use case.',
        prefix: 'Show',
        plural: false,
        shape: 'plain',
        returns: 'Details',
        creates: false,
        query: false,
        writes: false,
    },
};

/**
 * An aggregate's name in the plural, the way a list names it: good enough for a first draft of
 * the name, which the field still lets the user correct.
 */
function plural(word: string): string {
    if (/[^aeiou]y$/i.test(word)) {
        return `${word.slice(0, -1)}ies`;
    }

    return /(s|x|z|ch|sh)$/i.test(word) ? `${word}es` : `${word}s`;
}

/**
 * What `kit:apply` builds for a use case as the form stands: the handler's signature, what its
 * constructor injects and the classes `make:use-case` writes beside it. A list's Row and Sort are
 * named after what it lists, known here only while the name is the one its kind wrote.
 */
function useCaseOutline(
    data: PieceFormData,
    listed: string | null,
): { signature: string; injects: string[]; beside: string[] } {
    const name = data.name.trim() || '{Name}';
    const repositories = data.repositories.map(
        (repository) => `${repository.split('/').pop()}Repository`,
    );
    const item = listed === null ? '{Item}' : listed;

    return {
        signature:
            data.shape === 'plain'
                ? `${name}Handler::__invoke(…): ${data.returns || 'void'}`
                : `${name}Handler::__invoke(${name}Command $command): ${
                      data.shape === 'command-result'
                          ? `${name}Result`
                          : data.returns
                  }`,
        injects: [
            ...(data.creates === true ? ['IdGenerator'] : []),
            ...(data.query ? [`${name}Query`] : []),
            ...repositories,
            ...(repositories.length > 0 ? ['AuditLog'] : []),
        ],
        beside: [
            ...(data.shape === 'plain' ? [] : [`${name}Command`]),
            ...(data.shape === 'command-result' ? [`${name}Result`] : []),
            ...(data.query
                ? [
                      `${name}Criteria`,
                      `${item}ListRow`,
                      `${item}ListSort`,
                      `Eloquent${name}Query`,
                  ]
                : []),
        ],
    };
}

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
            applicationServices: 'plain',
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
        actor: false,
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
        section === 'useCases' || section === 'applicationServices',
        true,
    );
    const label = SECTION_LABELS[section];

    const ownAggregates = graph.manifests[context]?.aggregates ?? {};
    const aggregateNames = Object.keys(ownAggregates);
    const savedAggregates = aggregateNames.filter(
        (aggregate) => ownAggregates[aggregate]?.repository === true,
    );
    const [kind, setKind] = useState<UseCaseKind>('custom');
    const [subject, setSubject] = useState(aggregateNames[0] ?? '');
    const [verb, setVerb] = useState('');
    // What the last kind wrote, so a name or a return type the user typed is never written over.
    const [suggestedName, setSuggestedName] = useState('');
    const [suggestedReturns, setSuggestedReturns] = useState('');

    const preset = kind === 'custom' ? null : USE_CASE_KINDS[kind];
    // Adding a use case of a kind: its form asks only for what the kind leaves open.
    const tailored =
        section === 'useCases' && previous === null && preset !== null;
    const subjectChoices = preset?.writes ? savedAggregates : aggregateNames;

    const applyKind = (
        nextKind: UseCaseKind,
        wantedSubject: string,
        nextVerb: string,
    ) => {
        setKind(nextKind);
        setVerb(nextVerb);

        if (nextKind === 'custom') {
            return;
        }

        const next = USE_CASE_KINDS[nextKind];
        const choices = next.writes ? savedAggregates : aggregateNames;
        // A kind that writes needs an aggregate it can save through.
        const nextSubject = choices.includes(wantedSubject)
            ? wantedSubject
            : (choices[0] ?? '');
        setSubject(nextSubject);

        const draft =
            next.prefix === null && nextVerb.trim() === ''
                ? ''
                : (next.prefix ?? nextVerb.trim()) +
                  (next.plural ? plural(nextSubject) : nextSubject);
        const typedName =
            data.name.trim() !== '' && data.name !== suggestedName;

        if (!typedName) {
            setSuggestedName(draft);
        }

        const draftReturns =
            nextKind === 'show'
                ? nextSubject === ''
                    ? 'array'
                    : `${nextSubject}${next.returns}`
                : next.returns;
        const typedReturns =
            kind === 'show' &&
            nextKind === 'show' &&
            data.returns !== suggestedReturns;

        if (!typedReturns) {
            setSuggestedReturns(draftReturns);
        }

        // The aggregate's own repository first, the one kit:apply injects; the others stay.
        const others = data.repositories.filter(
            (repository) =>
                repository !== subject && repository !== nextSubject,
        );

        setData({
            ...data,
            name: typedName ? data.name : draft,
            shape: next.shape,
            returns: typedReturns ? data.returns : draftReturns,
            creates: next.creates,
            query: next.query,
            repositories: next.writes
                ? [...(nextSubject === '' ? [] : [nextSubject]), ...others]
                : [],
        });
    };

    const outline = useCaseOutline(
        data,
        kind === 'list' && data.name === suggestedName && subject !== ''
            ? subject
            : null,
    );

    return (
        <form onSubmit={submit} className="space-y-4">
            <h2 className="font-medium">
                {previous === null
                    ? `Add ${/^[aeiou]/.test(label) ? 'an' : 'a'} ${label} to ${context}`
                    : `Change ${previous}`}
            </h2>
            <InputError
                message={errors.version ?? errors.context ?? errors.section}
            />

            {section === 'useCases' && previous === null && (
                <>
                    <Field label="Kind">
                        <Select
                            value={kind}
                            onValueChange={(value) =>
                                applyKind(value as UseCaseKind, subject, verb)
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="custom">Custom</SelectItem>
                                {Object.entries(USE_CASE_KINDS).map(
                                    ([value, preset]) => (
                                        <SelectItem key={value} value={value}>
                                            {preset.label}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            {preset === null
                                ? 'Set every field yourself, or pick a kind for a form that asks only what it leaves open.'
                                : `${preset.hint} Pick Custom to change what the kind fixes.`}
                        </p>
                    </Field>
                    {preset !== null && (
                        <Field label="For the aggregate">
                            {subjectChoices.length > 0 ? (
                                <Choice
                                    value={subject}
                                    onChange={(value) =>
                                        applyKind(kind, value, verb)
                                    }
                                    options={subjectChoices}
                                />
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    {preset.writes
                                        ? `No aggregate of ${context} is saved through a repository yet. Add one first, or name the use case yourself.`
                                        : `${context} has no aggregate yet. Name the use case yourself.`}
                                </p>
                            )}
                        </Field>
                    )}
                    {preset !== null && preset.prefix === null && (
                        <Field label="Verb, such as Ship">
                            <Input
                                value={verb}
                                onChange={(event) =>
                                    applyKind(kind, subject, event.target.value)
                                }
                            />
                        </Field>
                    )}
                </>
            )}

            <Field label="Name" error={errors.name}>
                <Input
                    value={data.name}
                    onChange={(event) => setData('name', event.target.value)}
                    autoFocus
                />
            </Field>

            {tailored && kind === 'show' && (
                <Field
                    label="Read class it returns, written by hand"
                    error={errors.returns}
                >
                    <Input
                        value={data.returns}
                        onChange={(event) =>
                            setData('returns', event.target.value)
                        }
                    />
                </Field>
            )}

            {tailored && preset?.writes === true && (
                <Field
                    label="Other repositories it injects, added to the constructor by hand"
                    error={errors.repositories}
                >
                    {repositoryChoices.every((choice) =>
                        choice.values.every((value) => value === subject),
                    ) ? (
                        <p className="text-xs text-muted-foreground">
                            No other aggregate has a repository yet.
                        </p>
                    ) : (
                        repositoryChoices.map((choice) => (
                            <div key={choice.group} className="space-y-1">
                                <div className="text-xs text-muted-foreground">
                                    {choice.group}
                                </div>
                                {choice.values
                                    .filter((value) => value !== subject)
                                    .map((value) => (
                                        <Label
                                            key={value}
                                            className="flex items-center gap-2 font-normal"
                                        >
                                            <Checkbox
                                                checked={data.repositories.includes(
                                                    value,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    toggle(
                                                        value,
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            {value}
                                        </Label>
                                    ))}
                            </div>
                        ))
                    )}
                </Field>
            )}

            {tailored && (
                <div className="space-y-1 rounded-md border p-3 text-xs">
                    <div className="font-medium">What kit:apply builds</div>
                    <code className="block break-all">{outline.signature}</code>
                    <div className="text-muted-foreground">
                        Injects:{' '}
                        {outline.injects.length > 0
                            ? outline.injects.join(', ')
                            : 'nothing'}
                    </div>
                    {outline.beside.length > 0 && (
                        <div className="text-muted-foreground">
                            Beside it: {outline.beside.join(', ')}
                        </div>
                    )}
                    {kind === 'show' && (
                        <div className="text-muted-foreground">
                            Write {data.returns || 'the read class'} and the
                            port it reads through by hand: add the port with Add
                            ▸ Port, layer application.
                        </div>
                    )}
                    {kind === 'bulk' && (
                        <div className="text-muted-foreground">
                            Its Command carries the ids of the selection.
                        </div>
                    )}
                    {kind === 'list' && (
                        <div className="text-muted-foreground">
                            It reads through its query port, never a repository.
                        </div>
                    )}
                    {/* The fields the kind fixes are hidden, so their errors land here. */}
                    <InputError
                        message={
                            errors.shape ??
                            errors.creates ??
                            errors.query ??
                            (kind === 'show' ? undefined : errors.returns) ??
                            (preset?.writes === true
                                ? undefined
                                : errors.repositories)
                        }
                    />
                </div>
            )}

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

            {section === 'applicationServices' && (
                <>
                    <Field label="Shape of handle()" error={errors.shape}>
                        <Choice
                            value={data.shape}
                            onChange={(shape) => setData('shape', shape)}
                            options={['data', 'plain']}
                        />
                    </Field>
                    <Field
                        label="Options"
                        error={errors.actor ?? errors.exception}
                    >
                        <Label className="flex items-center gap-2 font-normal">
                            <Checkbox
                                checked={data.actor}
                                onCheckedChange={(checked) =>
                                    setData('actor', checked === true)
                                }
                            />
                            Reads who is acting through UserContext
                        </Label>
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

            {section === 'useCases' && !tailored && (
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

            {(section === 'services' ||
                section === 'applicationServices' ||
                section === 'useCases') &&
                !tailored && (
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
