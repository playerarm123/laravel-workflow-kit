import { useHttp } from '@inertiajs/react';
import { ArrowUp, X } from 'lucide-react';
import { useId } from 'react';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type {
    EnumBacking,
    StructureEndpoints,
    StructureGraph,
} from '@/kit/types';

/**
 * The shared kernel, whose enums and value objects belong to no aggregate.
 */
const SHARED = 'Shared';

/**
 * The types PHP spells itself, offered first for a value object's field.
 */
const BUILTIN_TYPES = ['string', 'int', 'bool', 'array', '?string', '?int'];

/**
 * One case of an enum or one field of a value object: a name and its value or type. A case of a
 * status also holds the cases it may become, by name.
 */
type Row = { name: string; value: string; next: string[] };

const EMPTY_ROW: Row = { name: '', value: '', next: [] };

type VocabularyFormData = {
    context: string;
    version: string;
    section: 'enums' | 'valueObjects';
    previous: string | null;
    name: string;
    aggregate: string | null;
    backing: EnumBacking;
    status: boolean;
    rows: Row[];
    cases: Record<string, string | null>;
    transitions: Record<string, string[]> | null;
    fields: Record<string, string>;
};

/**
 * A case's value when the form leaves it empty: its name in snake case, as `make:enum` writes it.
 */
function snake(name: string): string {
    return name.replace(/([a-z0-9])([A-Z])/g, '$1_$2').toLowerCase();
}

/**
 * The rows as the objects the manifest holds, in the order they are listed: a status's moves name
 * only cases the form still lists, in the order of the cases.
 */
function posted(
    section: 'enums' | 'valueObjects',
    backing: EnumBacking,
    status: boolean,
    rows: Row[],
): Pick<VocabularyFormData, 'cases' | 'transitions' | 'fields'> {
    const named = rows.filter((row) => row.name.trim() !== '');

    if (section === 'valueObjects') {
        return {
            cases: {},
            transitions: null,
            fields: Object.fromEntries(
                named.map((row) => [row.name.trim(), row.value.trim()]),
            ),
        };
    }

    const names = named.map((row) => row.name.trim());

    return {
        transitions: status
            ? Object.fromEntries(
                  named.map((row) => [
                      row.name.trim(),
                      names.filter((name) => row.next.includes(name)),
                  ]),
              )
            : null,
        cases: Object.fromEntries(
            named.map((row) => [
                row.name.trim(),
                backing === null
                    ? null
                    : row.value.trim() !== ''
                      ? row.value.trim()
                      : backing === 'string'
                        ? snake(row.name.trim())
                        : '',
            ]),
        ),
        fields: {},
    };
}

function startingValues(
    graph: StructureGraph,
    context: string,
    section: 'enums' | 'valueObjects',
    previous: string | null,
): VocabularyFormData {
    const manifest = graph.manifests[context];
    const firstAggregate = Object.keys(manifest?.aggregates ?? {})[0] ?? null;
    const base: VocabularyFormData = {
        context,
        version: graph.versions[context] ?? '',
        section,
        previous,
        name: previous ?? '',
        aggregate: context === SHARED ? null : firstAggregate,
        backing: 'string',
        status: false,
        rows: [EMPTY_ROW],
        cases: {},
        transitions: null,
        fields: {},
    };

    if (previous === null) {
        return base;
    }

    if (section === 'enums') {
        const entry = manifest?.enums[previous];

        if (entry === undefined) {
            return base;
        }

        const rows = Object.entries(entry.cases).map(([name, value]) => ({
            name,
            value: value === null ? '' : String(value),
            next: entry.transitions?.[name] ?? [],
        }));
        const status = entry.transitions !== null;

        return {
            ...base,
            aggregate: entry.aggregate,
            backing: entry.backing,
            status,
            rows,
            ...posted(section, entry.backing, status, rows),
        };
    }

    const entry = manifest?.valueObjects[previous];

    if (entry === undefined) {
        return base;
    }

    const rows = Object.entries(entry.fields).map(([name, value]) => ({
        name,
        value,
        next: [],
    }));

    return {
        ...base,
        aggregate: entry.aggregate,
        rows,
        ...posted(section, null, false, rows),
    };
}

/**
 * The types a field may name, written the way the manifest writes them: the builtins, then the
 * enums, value objects and entities of the same aggregate by name, then those of the shared kernel
 * and of the other aggregates with their prefix.
 */
export function typeChoices(
    graph: StructureGraph,
    context: string,
    aggregate: string | null,
): string[] {
    const own: string[] = [];
    const elsewhere: string[] = [];

    for (const [owner, manifest] of Object.entries(graph.manifests)) {
        if (manifest === undefined) {
            continue;
        }

        const name = (holder: string | null, type: string): string => {
            if (owner === context && holder === aggregate) {
                return type;
            }

            return holder === null
                ? `${owner}/${type}`
                : `${owner}/${holder}/${type}`;
        };
        const add = (holder: string | null, type: string) =>
            (owner === context && holder === aggregate ? own : elsewhere).push(
                name(holder, type),
            );

        for (const [type, entry] of Object.entries(manifest.enums)) {
            add(entry?.aggregate ?? null, type);
        }

        for (const [type, entry] of Object.entries(manifest.valueObjects)) {
            add(entry?.aggregate ?? null, type);
        }

        for (const [holder, entry] of Object.entries(manifest.aggregates)) {
            add(holder, `${holder}Entity`);

            for (const child of entry?.children ?? []) {
                add(holder, `${child}Entity`);
            }
        }
    }

    return [...BUILTIN_TYPES, ...own.sort(), ...elsewhere.sort()];
}

export function Field({
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

/**
 * Adds an enum or a value object to a context, or changes one the code does not have yet. Its
 * cases or fields are rows in the order the class declares them. The server checks the manifest's
 * shape and the design rules, a field's type against what the manifests design, and answers with
 * the graph drawn again.
 */
export function VocabularyForm({
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
    section: 'enums' | 'valueObjects';
    previous: string | null;
    onSaved: (graph: StructureGraph, name: string) => void;
    onCancel: () => void;
}) {
    const form = useHttp<VocabularyFormData, { graph: StructureGraph }>(
        startingValues(graph, context, section, previous),
    );
    const { data, setData, errors, processing } = form;
    const typesId = useId();
    const statusId = useId();
    const isEnum = section === 'enums';
    const aggregates = Object.keys(graph.manifests[context]?.aggregates ?? {});

    const change = (next: Partial<VocabularyFormData>) => {
        const merged = { ...data, ...next };

        setData({
            ...merged,
            ...posted(section, merged.backing, merged.status, merged.rows),
        });
    };

    /**
     * Changes one row. A case renamed keeps the moves that lead to it under its new name.
     */
    const setRow = (index: number, row: Partial<Row>) => {
        const before = data.rows[index].name.trim();
        const after = row.name?.trim();

        change({
            rows: data.rows.map((current, at) => {
                if (at === index) {
                    return { ...current, ...row };
                }

                return after === undefined || before === ''
                    ? current
                    : {
                          ...current,
                          next: current.next.map((name) =>
                              name === before ? after : name,
                          ),
                      };
            }),
        });
    };

    /**
     * Removes one row, and every move that led to it.
     */
    const removeRow = (index: number) => {
        const gone = data.rows[index].name.trim();

        change({
            rows: data.rows
                .filter((_, at) => at !== index)
                .map((current) => ({
                    ...current,
                    next: current.next.filter((name) => name !== gone),
                })),
        });
    };

    const caseNames = data.rows
        .map((row) => row.name.trim())
        .filter((name) => name !== '');

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
                    ? `Add ${isEnum ? 'an enum' : 'a value object'} to ${context}`
                    : `Change ${previous}`}
            </h2>
            <InputError
                message={errors.version ?? errors.context ?? errors.section}
            />

            <Field label="Name" error={errors.name}>
                <Input
                    value={data.name}
                    onChange={(event) => change({ name: event.target.value })}
                    autoFocus
                />
            </Field>

            {context !== SHARED && (
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

            {isEnum && (
                <Field label="Backing" error={errors.backing}>
                    <Select
                        value={data.backing ?? 'pure'}
                        onValueChange={(backing) =>
                            change({
                                backing:
                                    backing === 'pure'
                                        ? null
                                        : (backing as 'string' | 'int'),
                            })
                        }
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="string">string</SelectItem>
                            <SelectItem value="int">int</SelectItem>
                            <SelectItem value="pure">pure</SelectItem>
                        </SelectContent>
                    </Select>
                </Field>
            )}

            {isEnum && (
                <div className="space-y-1.5">
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id={statusId}
                            checked={data.status}
                            onCheckedChange={(checked) =>
                                change({ status: checked === true })
                            }
                        />
                        <Label htmlFor={statusId}>
                            A status: each case lists the cases it may become
                            (states.md)
                        </Label>
                    </div>
                    <InputError message={errors.transitions} />
                </div>
            )}

            <Field
                label={
                    isEnum ? 'Cases, in order' : 'Fields, in constructor order'
                }
                error={isEnum ? errors.cases : errors.fields}
            >
                <datalist id={typesId}>
                    {typeChoices(graph, context, data.aggregate).map((type) => (
                        <option key={type} value={type} />
                    ))}
                </datalist>
                <div className="space-y-2">
                    {data.rows.map((row, index) => (
                        <div key={index} className="space-y-1.5">
                            <div className="flex items-center gap-1.5">
                                <Input
                                    value={row.name}
                                    placeholder={isEnum ? 'Case' : 'field'}
                                    onChange={(event) =>
                                        setRow(index, {
                                            name: event.target.value,
                                        })
                                    }
                                />
                                {(!isEnum || data.backing !== null) && (
                                    <Input
                                        value={row.value}
                                        list={isEnum ? undefined : typesId}
                                        placeholder={
                                            isEnum
                                                ? data.backing === 'string'
                                                    ? snake(row.name) || 'value'
                                                    : '1'
                                                : 'Type'
                                        }
                                        onChange={(event) =>
                                            setRow(index, {
                                                value: event.target.value,
                                            })
                                        }
                                    />
                                )}
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
                                    onClick={() => removeRow(index)}
                                >
                                    <X />
                                </Button>
                            </div>
                            {isEnum &&
                                data.status &&
                                row.name.trim() !== '' && (
                                    <div className="flex flex-wrap items-center gap-1.5 pl-2 text-xs text-muted-foreground">
                                        <span>may become</span>
                                        <ToggleGroup
                                            type="multiple"
                                            variant="outline"
                                            size="sm"
                                            aria-label={`What ${row.name.trim()} may become`}
                                            value={row.next}
                                            onValueChange={(next) =>
                                                setRow(index, { next })
                                            }
                                        >
                                            {caseNames
                                                .filter(
                                                    (name) =>
                                                        name !==
                                                        row.name.trim(),
                                                )
                                                .map((name) => (
                                                    <ToggleGroupItem
                                                        key={name}
                                                        value={name}
                                                        className="h-7 px-2 text-xs"
                                                    >
                                                        {name}
                                                    </ToggleGroupItem>
                                                ))}
                                        </ToggleGroup>
                                        {row.next.length === 0 && (
                                            <span>· final</span>
                                        )}
                                    </div>
                                )}
                        </div>
                    ))}
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() =>
                            change({
                                rows: [...data.rows, EMPTY_ROW],
                            })
                        }
                    >
                        Add {isEnum ? 'a case' : 'a field'}
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
