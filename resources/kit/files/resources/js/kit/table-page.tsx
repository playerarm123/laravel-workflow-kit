import { useHttp } from '@inertiajs/react';
import { ArrowLeft, Lock, Plus, X } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
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
import { endpointFor } from '@/kit/panel-sections';
import { StatusBadge } from '@/kit/structure-node';
import type {
    OnDelete,
    ProposedTable,
    StructureEndpoints,
    StructureGraph,
    TableColumn,
    TableDesign,
    TableForeignKey,
    TableNeighbour,
} from '@/kit/types';
import { Field, typeChoices } from '@/kit/vocabulary-form';

/**
 * Where a table page stands: the context, and the entity whose table it is or the name of one of
 * the context's tables. Both null is a new table of the context.
 */
export type TablePlace = {
    context: string;
    entity: string | null;
    table: string | null;
};

/** The Blueprint methods a column may be built with, as StructureTableDesign lists them. */
const COLUMN_TYPES = [
    'string',
    'char',
    'text',
    'tinyText',
    'mediumText',
    'longText',
    'integer',
    'bigInteger',
    'smallInteger',
    'tinyInteger',
    'mediumInteger',
    'unsignedInteger',
    'unsignedBigInteger',
    'unsignedSmallInteger',
    'unsignedTinyInteger',
    'unsignedMediumInteger',
    'boolean',
    'decimal',
    'date',
    'time',
    'timeTz',
    'dateTime',
    'dateTimeTz',
    'timestamp',
    'timestampTz',
    'year',
    'jsonb',
    'uuid',
    'ulid',
    'binary',
    'ipAddress',
    'macAddress',
];

const ON_DELETE: { value: OnDelete; label: string }[] = [
    { value: 'cascade', label: 'cascade: rows it owns' },
    { value: 'restrict', label: 'restrict: a reference to another aggregate' },
    { value: 'null', label: 'null: an optional reference' },
    { value: 'noAction', label: 'no action' },
];

type ColumnRow = {
    name: string;
    type: string;
    length: string;
    precision: string;
    scale: string;
    enum: string;
    nullable: boolean;
    unique: boolean;
    index: boolean;
    comment: string;
    default?: TableColumn['default'];
};

type KeyRow = {
    name: string;
    table: string;
    onDelete: OnDelete | '';
    nullable: boolean;
    unique: boolean;
    index: boolean;
    comment: string;
};

type IndexRow = { columns: string; unique: boolean; comment: string };

type TableFormData = {
    name: string;
    model: string;
    description: string;
    timestamps: boolean;
    softDeletes: boolean;
    columns: ColumnRow[];
    foreignKeys: KeyRow[];
    indexes: IndexRow[];
};

/**
 * What the page posts: the rows it edits, and the design they say beside the manifest's version,
 * the entity whose table it is and the table the design replaces, each empty when there is none.
 */
type TablePost = TableFormData & {
    version: string;
    entity: string;
    previous: string;
    table: TableDesign | null;
};

type TableRemoval = { version: string; entity: string; name: string };

/**
 * The table page the url's hash names: `#table/{Context}/@{Entity}` for an entity's table,
 * `#table/{Context}/{table}` for one of the context's tables, and `#table/{Context}/+` for a new one.
 */
export function tablePlaceOf(hash: string): TablePlace | null {
    const [view, context, key] = hash.replace(/^#/, '').split('/');

    if (view !== 'table' || !context || !key) {
        return null;
    }

    const name = decodeURIComponent(key);

    return {
        context: decodeURIComponent(context),
        entity: name.startsWith('@') ? name.slice(1) : null,
        table: name.startsWith('@') || name === '+' ? null : name,
    };
}

export function tableHref(place: TablePlace): string {
    const key =
        place.entity !== null ? `@${place.entity}` : (place.table ?? '+');

    return `#table/${encodeURIComponent(place.context)}/${encodeURIComponent(key)}`;
}

/**
 * The design a page edits, as its manifest holds it, or null while there is none.
 */
function designOf(
    graph: StructureGraph,
    place: TablePlace,
): TableDesign | null {
    const manifest = graph.manifests[place.context];

    if (place.entity !== null) {
        return manifest?.entities[place.entity]?.table ?? null;
    }

    return place.table === null
        ? null
        : (manifest?.tables[place.table] ?? null);
}

function text(value: number | undefined): string {
    return value === undefined ? '' : String(value);
}

function rowsOf(design: TableDesign | null, name: string): TableFormData {
    return {
        name: design?.name ?? name,
        model: design?.model ?? '',
        description: design?.description ?? '',
        timestamps: design?.timestamps ?? true,
        softDeletes: design?.softDeletes ?? false,
        columns: Object.entries(design?.columns ?? {}).map(
            ([column, definition]): ColumnRow => ({
                name: column,
                type: definition?.type ?? 'string',
                length: text(definition?.length),
                precision: text(definition?.precision),
                scale: text(definition?.scale),
                enum: definition?.enum ?? '',
                nullable: definition?.nullable ?? false,
                unique: definition?.unique ?? false,
                index: definition?.index ?? false,
                comment: definition?.comment ?? '',
                default: definition?.default,
            }),
        ),
        foreignKeys: Object.entries(design?.foreignKeys ?? {}).map(
            ([column, key]): KeyRow => ({
                name: column,
                table: key?.table ?? '',
                onDelete: key?.onDelete ?? '',
                nullable: key?.nullable ?? false,
                unique: key?.unique ?? false,
                index: key?.index ?? false,
                comment: key?.comment ?? '',
            }),
        ),
        indexes: (design?.indexes ?? []).map((index): IndexRow => ({
            columns: index.columns.join(', '),
            unique: index.unique,
            comment: index.comment ?? '',
        })),
    };
}

function whole(value: string): number | undefined {
    return /^\d+$/.test(value.trim()) ? Number(value.trim()) : undefined;
}

function written(value: string): string | undefined {
    return value.trim() === '' ? undefined : value.trim();
}

/**
 * The design the rows say, in the shape the manifest holds it. An empty field is left out.
 */
function designFrom(data: TableFormData, entity: string | null): TableDesign {
    const columns: Record<string, TableColumn> = {};
    const foreignKeys: Record<string, TableForeignKey> = {};

    for (const row of data.columns) {
        columns[row.name.trim()] = {
            type: row.type.trim(),
            length: whole(row.length),
            precision: whole(row.precision),
            scale: whole(row.scale),
            enum: written(row.enum),
            nullable: row.nullable || undefined,
            unique: row.unique || undefined,
            index: row.index || undefined,
            default: row.default,
            comment: written(row.comment),
        };
    }

    for (const row of data.foreignKeys) {
        foreignKeys[row.name.trim()] = {
            table: row.table.trim(),
            onDelete: row.onDelete === '' ? undefined : row.onDelete,
            nullable: row.nullable || undefined,
            unique: row.unique || undefined,
            index: row.index || undefined,
            comment: written(row.comment),
        };
    }

    return {
        name: data.name.trim(),
        model: written(data.model) ?? null,
        ...(entity === null && written(data.description) !== undefined
            ? { description: written(data.description) }
            : {}),
        columns,
        foreignKeys,
        indexes: data.indexes.map((row) => ({
            columns: row.columns
                .split(',')
                .map((column) => column.trim())
                .filter((column) => column !== ''),
            unique: row.unique,
            comment: written(row.comment),
        })),
        timestamps: data.timestamps,
        softDeletes: data.softDeletes,
    };
}

function snake(name: string): string {
    return name.replace(/[A-Z]/g, (letter) => `_${letter.toLowerCase()}`);
}

/**
 * Where a neighbouring table's page is, or null for one no manifest designs.
 */
function neighbourHref(neighbour: TableNeighbour): string | null {
    return neighbour.context === null
        ? null
        : tableHref({
              context: neighbour.context,
              entity: neighbour.entity,
              table: neighbour.entity === null ? neighbour.table : null,
          });
}

/**
 * A table drawn as a cylinder, the way an ER diagram draws one, opening its page when a manifest
 * designs it.
 */
function Cylinder({
    name,
    note,
    href,
}: {
    name: string;
    note: string;
    href: string | null;
}) {
    const body = (
        <div className="flex w-44 flex-col items-center text-center">
            <div className="h-4 w-full rounded-[50%] border bg-muted" />
            <div className="-mt-2 w-full rounded-b-[50%_1rem] border border-t-0 bg-card px-3 pt-3 pb-4">
                <div className="truncate font-mono text-xs font-medium">
                    {name}
                </div>
                <div className="truncate text-[11px] text-muted-foreground">
                    {note}
                </div>
            </div>
        </div>
    );

    return href === null ? (
        <div title={`${name}: no manifest designs it`}>{body}</div>
    ) : (
        <a href={href} className="transition-opacity hover:opacity-80">
            {body}
        </a>
    );
}

function Section({
    title,
    action,
    children,
}: {
    title: string;
    action?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="space-y-2">
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-medium">{title}</h3>
                {action}
            </div>
            {children}
        </section>
    );
}

function Flag({
    label,
    checked,
    disabled,
    onChange,
}: {
    label: string;
    checked: boolean;
    disabled?: boolean;
    onChange: (checked: boolean) => void;
}) {
    const id = useId();

    return (
        <div className="flex items-center gap-1.5">
            <Checkbox
                id={id}
                checked={checked}
                disabled={disabled}
                onCheckedChange={(value) => onChange(value === true)}
            />
            <Label htmlFor={id} className="text-xs font-normal">
                {label}
            </Label>
        </div>
    );
}

/**
 * The page of one table (structure.md, "Tables"): an entity's beside the state it maps, or one of
 * the context's tables, with its columns, foreign keys and indexes, timestamps and soft deletes,
 * what its model holds, and the tables around it in the ER diagram. Its header carries the status
 * of the steps that build it. What the migrations build is locked; what the design gains,
 * kit:apply adds with a migration of its own. An entity without a table starts from the one its
 * state proposes. The server checks the design and answers with the graph drawn again.
 */
export function TablePage({
    graph,
    endpoints,
    place,
    onSaved,
    onRemoved,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    place: TablePlace;
    onSaved: (graph: StructureGraph, place: TablePlace) => void;
    onRemoved: (graph: StructureGraph, message: string) => void;
}) {
    const { context, entity } = place;
    const design = designOf(graph, place);
    const previous = design === null ? null : (design.name ?? place.table);
    const status =
        previous === null ? undefined : graph.tables[context]?.[previous];
    const builtColumns = status?.builtColumns ?? [];
    const built = status?.built ?? false;
    const entry =
        entity === null
            ? undefined
            : graph.manifests[context]?.entities[entity];
    const form = useHttp<TablePost, { graph: StructureGraph }>({
        ...rowsOf(design, place.table ?? ''),
        version: '',
        entity: entity ?? '',
        previous: '',
        table: null,
    });
    const { data, setData, processing } = form;
    const errors = form.errors as Partial<Record<string, string>>;
    const removal = useHttp<TableRemoval, { graph: StructureGraph }>({
        version: '',
        entity: entity ?? '',
        name: '',
    });
    const proposal = useHttp<Record<string, never>, ProposedTable>({});
    const [unmapped, setUnmapped] = useState<Partial<Record<string, string>>>(
        {},
    );
    const [proposed, setProposed] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const asked = useRef(false);
    const typesId = useId();
    const enumsId = useId();
    const tablesId = useId();
    const aggregate = entry?.aggregate ?? null;

    useEffect(() => {
        if (asked.current || entity === null || design !== null) {
            return;
        }

        asked.current = true;
        proposal.get(
            endpoints.proposedTable
                .replace('__CONTEXT__', encodeURIComponent(context))
                .replace('__ENTITY__', encodeURIComponent(entity)),
            {
                onSuccess: (response) => {
                    const table = response.table;

                    if (table !== null) {
                        setData({ ...data, ...rowsOf(table, '') });
                        setProposed(true);
                    }

                    setUnmapped(response.unmapped);
                },
            },
        );
    }, [context, entity, design, proposal, endpoints, data, setData]);

    const change = (patch: Partial<TableFormData>) =>
        setData({ ...data, ...patch });

    const changeColumn = (at: number, patch: Partial<ColumnRow>) =>
        change({
            columns: data.columns.map((row, index) =>
                index === at ? { ...row, ...patch } : row,
            ),
        });

    const changeKey = (at: number, patch: Partial<KeyRow>) =>
        change({
            foreignKeys: data.foreignKeys.map((row, index) =>
                index === at ? { ...row, ...patch } : row,
            ),
        });

    const changeIndex = (at: number, patch: Partial<IndexRow>) =>
        change({
            indexes: data.indexes.map((row, index) =>
                index === at ? { ...row, ...patch } : row,
            ),
        });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((values) => ({
            ...values,
            version: graph.versions[context] ?? '',
            entity: entity ?? '',
            previous: previous ?? '',
            table: designFrom(values, entity),
        }));
        form.post(endpointFor(endpoints.saveTable, context), {
            onSuccess: (response) =>
                onSaved(response.graph, {
                    context,
                    entity,
                    table: entity === null ? data.name.trim() : null,
                }),
        });
    };

    const remove = () => {
        if (previous === null) {
            return;
        }

        removal.transform((values) => ({
            ...values,
            version: graph.versions[context] ?? '',
            entity: entity ?? '',
            name: previous,
        }));
        removal.post(endpointFor(endpoints.removeTable, context), {
            onSuccess: (response) =>
                onRemoved(response.graph, `Removed ${previous}`),
        });
    };

    const title =
        entity !== null
            ? `${entity}'s table`
            : previous === null
              ? 'New table'
              : previous;

    return (
        <div className="min-h-0 flex-1 overflow-y-auto">
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-6xl space-y-6 p-6 text-sm"
            >
                <header className="space-y-2">
                    <div className="flex flex-wrap items-center gap-3">
                        <Button asChild size="sm" variant="ghost">
                            <a href={`#context/${encodeURIComponent(context)}`}>
                                <ArrowLeft />
                                {context}
                            </a>
                        </Button>
                        <h2 className="text-lg font-medium">{title}</h2>
                        {status !== undefined && (
                            <StatusBadge status={status.status} />
                        )}
                        {built && (
                            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                <Lock className="size-3" />
                                the migrations build it
                            </span>
                        )}
                        <div className="ml-auto flex gap-2">
                            {previous !== null &&
                                !built &&
                                (confirming ? (
                                    <>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="destructive"
                                            disabled={removal.processing}
                                            onClick={remove}
                                        >
                                            Remove {previous}
                                        </Button>
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => setConfirming(false)}
                                        >
                                            Keep it
                                        </Button>
                                    </>
                                ) : (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setConfirming(true)}
                                    >
                                        Remove
                                    </Button>
                                ))}
                            <Button
                                type="submit"
                                size="sm"
                                disabled={processing}
                            >
                                {previous === null ? 'Add table' : 'Save'}
                            </Button>
                        </div>
                    </div>
                    {status?.status === 'waiting' && status.reason !== null && (
                        <p className="text-muted-foreground">
                            Waits: {status.reason}
                        </p>
                    )}
                    {status?.schema?.read === false && (
                        <p className="text-amber-700 dark:text-amber-400">
                            The schema could not be read
                            {status.schema.message !== null &&
                                ` (${status.schema.message})`}
                            , so what this page shows is what the migrations
                            say, not what the database holds.
                        </p>
                    )}
                    {status?.schema !== null &&
                        status?.schema !== undefined &&
                        status.schema.differences.length > 0 && (
                            <div className="text-destructive">
                                <p>Differs from the database:</p>
                                <ul className="list-disc pl-5">
                                    {status.schema.differences.map(
                                        (difference) => (
                                            <li key={difference}>
                                                {difference}
                                            </li>
                                        ),
                                    )}
                                </ul>
                            </div>
                        )}
                    {status?.schema?.read === true &&
                        status.schema.differences.length === 0 && (
                            <p className="text-muted-foreground">
                                The database holds it as the migrations build
                                it.
                            </p>
                        )}
                    {status?.command !== null &&
                        status?.command !== undefined && (
                            <p className="text-muted-foreground">
                                Ready: <code>{status.command}</code>
                            </p>
                        )}
                    {proposed && (
                        <p className="text-muted-foreground">
                            Proposed from {entity}&apos;s state. Choose each
                            foreign key&apos;s delete behaviour and say what
                            null means, then add it.
                        </p>
                    )}
                    <InputError
                        message={
                            errors.version ?? errors.context ?? errors.entity
                        }
                    />
                    <InputError message={removal.errors.name} />
                </header>

                <div
                    className={
                        entry === undefined
                            ? 'space-y-6'
                            : 'grid gap-6 lg:grid-cols-[16rem_1fr]'
                    }
                >
                    {entry !== undefined && (
                        <Section title={`${entity}'s state`}>
                            <ul className="space-y-1">
                                {Object.entries(entry.state).map(
                                    ([property, type]) => {
                                        const column = snake(property);
                                        const mapped =
                                            data.columns.some(
                                                (row) => row.name === column,
                                            ) ||
                                            data.foreignKeys.some(
                                                (row) => row.name === column,
                                            );

                                        return (
                                            <li
                                                key={property}
                                                className="font-mono text-xs"
                                            >
                                                {property}: {type}
                                                <span className="block text-muted-foreground">
                                                    {mapped
                                                        ? `→ ${column}`
                                                        : (unmapped[property] ??
                                                          'no column')}
                                                </span>
                                            </li>
                                        );
                                    },
                                )}
                            </ul>
                        </Section>
                    )}

                    <div className="space-y-6">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Table" error={errors.name}>
                                <Input
                                    value={data.name}
                                    placeholder="crates"
                                    disabled={built}
                                    onChange={(event) =>
                                        change({ name: event.target.value })
                                    }
                                />
                            </Field>
                            <Field label="Model" error={errors.model}>
                                <Input
                                    value={data.model}
                                    placeholder={
                                        entity === null ? 'none' : 'Crate'
                                    }
                                    onChange={(event) =>
                                        change({ model: event.target.value })
                                    }
                                />
                            </Field>
                            {entity === null && (
                                <div className="sm:col-span-2">
                                    <Field
                                        label="What it is for"
                                        error={errors.description}
                                    >
                                        <Input
                                            value={data.description}
                                            placeholder="Join table between crates and tags"
                                            onChange={(event) =>
                                                change({
                                                    description:
                                                        event.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                </div>
                            )}
                            <div className="flex gap-4">
                                <Flag
                                    label="timestamps"
                                    checked={data.timestamps}
                                    disabled={built && data.timestamps}
                                    onChange={(timestamps) =>
                                        change({ timestamps })
                                    }
                                />
                                <Flag
                                    label="soft deletes"
                                    checked={data.softDeletes}
                                    disabled={built && data.softDeletes}
                                    onChange={(softDeletes) =>
                                        change({ softDeletes })
                                    }
                                />
                            </div>
                            <InputError
                                message={
                                    errors.timestamps ?? errors.softDeletes
                                }
                            />
                        </div>

                        <datalist id={typesId}>
                            {COLUMN_TYPES.map((type) => (
                                <option key={type} value={type} />
                            ))}
                        </datalist>
                        <datalist id={enumsId}>
                            {typeChoices(graph, context, aggregate).map(
                                (type) => (
                                    <option key={type} value={type} />
                                ),
                            )}
                        </datalist>
                        <datalist id={tablesId}>
                            {Object.entries(graph.tables).flatMap(
                                ([, tables]) =>
                                    Object.keys(tables ?? {}).map((table) => (
                                        <option key={table} value={table} />
                                    )),
                            )}
                        </datalist>

                        <Section
                            title="Columns"
                            action={
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        change({
                                            columns: [
                                                ...data.columns,
                                                {
                                                    name: '',
                                                    type: 'string',
                                                    length: '',
                                                    precision: '',
                                                    scale: '',
                                                    enum: '',
                                                    nullable: false,
                                                    unique: false,
                                                    index: false,
                                                    comment: '',
                                                },
                                            ],
                                        })
                                    }
                                >
                                    <Plus />
                                    Column
                                </Button>
                            }
                        >
                            <p className="text-xs text-muted-foreground">
                                Every table is keyed on its uuid id.
                            </p>
                            {data.columns.map((row, at) => {
                                const locked = builtColumns.includes(row.name);

                                return (
                                    <div
                                        key={at}
                                        className="space-y-1 rounded-md border p-2"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Input
                                                className="w-40 font-mono"
                                                value={row.name}
                                                placeholder="status"
                                                disabled={locked}
                                                onChange={(event) =>
                                                    changeColumn(at, {
                                                        name: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                            <Input
                                                className="w-44 font-mono"
                                                value={row.type}
                                                list={typesId}
                                                disabled={locked}
                                                onChange={(event) =>
                                                    changeColumn(at, {
                                                        type: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                            {row.type === 'decimal' ? (
                                                <>
                                                    <Input
                                                        className="w-20"
                                                        value={row.precision}
                                                        placeholder="precision"
                                                        disabled={locked}
                                                        onChange={(event) =>
                                                            changeColumn(at, {
                                                                precision:
                                                                    event.target
                                                                        .value,
                                                            })
                                                        }
                                                    />
                                                    <Input
                                                        className="w-20"
                                                        value={row.scale}
                                                        placeholder="scale"
                                                        disabled={locked}
                                                        onChange={(event) =>
                                                            changeColumn(at, {
                                                                scale: event
                                                                    .target
                                                                    .value,
                                                            })
                                                        }
                                                    />
                                                </>
                                            ) : (
                                                <Input
                                                    className="w-20"
                                                    value={row.length}
                                                    placeholder="length"
                                                    disabled={locked}
                                                    onChange={(event) =>
                                                        changeColumn(at, {
                                                            length: event.target
                                                                .value,
                                                        })
                                                    }
                                                />
                                            )}
                                            <Input
                                                className="w-44 font-mono"
                                                value={row.enum}
                                                list={enumsId}
                                                placeholder="enum it casts to"
                                                disabled={locked}
                                                onChange={(event) =>
                                                    changeColumn(at, {
                                                        enum: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                            <Flag
                                                label="nullable"
                                                checked={row.nullable}
                                                disabled={locked}
                                                onChange={(nullable) =>
                                                    changeColumn(at, {
                                                        nullable,
                                                    })
                                                }
                                            />
                                            <Flag
                                                label="unique"
                                                checked={row.unique}
                                                disabled={locked}
                                                onChange={(unique) =>
                                                    changeColumn(at, { unique })
                                                }
                                            />
                                            <Flag
                                                label="index"
                                                checked={row.index}
                                                disabled={locked}
                                                onChange={(index) =>
                                                    changeColumn(at, { index })
                                                }
                                            />
                                            {locked ? (
                                                <Lock className="ml-auto size-3.5 text-muted-foreground" />
                                            ) : (
                                                <Button
                                                    type="button"
                                                    size="icon"
                                                    variant="ghost"
                                                    className="ml-auto size-7"
                                                    aria-label={`Remove ${row.name}`}
                                                    onClick={() =>
                                                        change({
                                                            columns:
                                                                data.columns.filter(
                                                                    (
                                                                        _,
                                                                        index,
                                                                    ) =>
                                                                        index !==
                                                                        at,
                                                                ),
                                                        })
                                                    }
                                                >
                                                    <X />
                                                </Button>
                                            )}
                                        </div>
                                        <Input
                                            value={row.comment}
                                            placeholder={
                                                row.nullable
                                                    ? 'null means …'
                                                    : 'comment'
                                            }
                                            disabled={locked}
                                            onChange={(event) =>
                                                changeColumn(at, {
                                                    comment: event.target.value,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors[`columns.${row.name}`]
                                            }
                                        />
                                    </div>
                                );
                            })}
                        </Section>

                        <Section
                            title="Foreign keys"
                            action={
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        change({
                                            foreignKeys: [
                                                ...data.foreignKeys,
                                                {
                                                    name: '',
                                                    table: '',
                                                    onDelete: '',
                                                    nullable: false,
                                                    unique: false,
                                                    index: true,
                                                    comment: '',
                                                },
                                            ],
                                        })
                                    }
                                >
                                    <Plus />
                                    Foreign key
                                </Button>
                            }
                        >
                            {data.foreignKeys.map((row, at) => {
                                const locked = builtColumns.includes(row.name);

                                return (
                                    <div
                                        key={at}
                                        className="space-y-1 rounded-md border p-2"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Input
                                                className="w-40 font-mono"
                                                value={row.name}
                                                placeholder="shipper_id"
                                                disabled={locked}
                                                onChange={(event) =>
                                                    changeKey(at, {
                                                        name: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                            <Input
                                                className="w-44 font-mono"
                                                value={row.table}
                                                list={tablesId}
                                                placeholder="parent table"
                                                disabled={locked}
                                                onChange={(event) =>
                                                    changeKey(at, {
                                                        table: event.target
                                                            .value,
                                                    })
                                                }
                                            />
                                            <Select
                                                value={row.onDelete}
                                                disabled={locked}
                                                onValueChange={(onDelete) =>
                                                    changeKey(at, {
                                                        onDelete:
                                                            onDelete as OnDelete,
                                                    })
                                                }
                                            >
                                                <SelectTrigger className="w-72">
                                                    <SelectValue placeholder="on delete of the parent…" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {ON_DELETE.map((choice) => (
                                                        <SelectItem
                                                            key={choice.value}
                                                            value={choice.value}
                                                        >
                                                            {choice.label}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <Flag
                                                label="nullable"
                                                checked={row.nullable}
                                                disabled={locked}
                                                onChange={(nullable) =>
                                                    changeKey(at, { nullable })
                                                }
                                            />
                                            <Flag
                                                label="unique"
                                                checked={row.unique}
                                                disabled={locked}
                                                onChange={(unique) =>
                                                    changeKey(at, { unique })
                                                }
                                            />
                                            <Flag
                                                label="index"
                                                checked={row.index}
                                                disabled={locked}
                                                onChange={(index) =>
                                                    changeKey(at, { index })
                                                }
                                            />
                                            {locked ? (
                                                <Lock className="ml-auto size-3.5 text-muted-foreground" />
                                            ) : (
                                                <Button
                                                    type="button"
                                                    size="icon"
                                                    variant="ghost"
                                                    className="ml-auto size-7"
                                                    aria-label={`Remove ${row.name}`}
                                                    onClick={() =>
                                                        change({
                                                            foreignKeys:
                                                                data.foreignKeys.filter(
                                                                    (
                                                                        _,
                                                                        index,
                                                                    ) =>
                                                                        index !==
                                                                        at,
                                                                ),
                                                        })
                                                    }
                                                >
                                                    <X />
                                                </Button>
                                            )}
                                        </div>
                                        <Input
                                            value={row.comment}
                                            placeholder={
                                                row.nullable
                                                    ? 'null means …'
                                                    : 'comment'
                                            }
                                            disabled={locked}
                                            onChange={(event) =>
                                                changeKey(at, {
                                                    comment: event.target.value,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors[
                                                    `foreignKeys.${row.name}`
                                                ]
                                            }
                                        />
                                    </div>
                                );
                            })}
                        </Section>

                        <Section
                            title="Indexes"
                            action={
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    onClick={() =>
                                        change({
                                            indexes: [
                                                ...data.indexes,
                                                {
                                                    columns: '',
                                                    unique: false,
                                                    comment: '',
                                                },
                                            ],
                                        })
                                    }
                                >
                                    <Plus />
                                    Index
                                </Button>
                            }
                        >
                            <p className="text-xs text-muted-foreground">
                                An index on one column is that column&apos;s
                                index or unique flag. List one here when it
                                spans more than one, in order.
                            </p>
                            {data.indexes.map((row, at) => (
                                <div
                                    key={at}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    <Input
                                        className="w-72 font-mono"
                                        value={row.columns}
                                        placeholder="crate_id, tag"
                                        onChange={(event) =>
                                            changeIndex(at, {
                                                columns: event.target.value,
                                            })
                                        }
                                    />
                                    <Flag
                                        label="unique"
                                        checked={row.unique}
                                        onChange={(unique) =>
                                            changeIndex(at, { unique })
                                        }
                                    />
                                    <Input
                                        className="w-64"
                                        value={row.comment}
                                        placeholder="comment"
                                        onChange={(event) =>
                                            changeIndex(at, {
                                                comment: event.target.value,
                                            })
                                        }
                                    />
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="size-7"
                                        aria-label="Remove the index"
                                        onClick={() =>
                                            change({
                                                indexes: data.indexes.filter(
                                                    (_, index) => index !== at,
                                                ),
                                            })
                                        }
                                    >
                                        <X />
                                    </Button>
                                </div>
                            ))}
                            <InputError message={errors.indexes} />
                        </Section>

                        {status?.model !== null &&
                            status?.model !== undefined && (
                                <Section title={`Model ${data.model}`}>
                                    <dl className="grid grid-cols-[6rem_1fr] gap-x-3 gap-y-1 text-xs">
                                        <dt className="text-muted-foreground">
                                            fillable
                                        </dt>
                                        <dd className="font-mono">
                                            {status.model.fillable.join(', ') ||
                                                '—'}
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            casts
                                        </dt>
                                        <dd className="font-mono">
                                            {Object.entries(status.model.casts)
                                                .map(
                                                    ([column, cast]) =>
                                                        `${column}: ${cast ?? ''}`,
                                                )
                                                .join(', ') || '—'}
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            relations
                                        </dt>
                                        <dd className="font-mono">
                                            {status.model.relations.join(
                                                ', ',
                                            ) || '—'}
                                        </dd>
                                    </dl>
                                </Section>
                            )}
                    </div>
                </div>

                {status !== undefined &&
                    (status.parents.length > 0 ||
                        status.children.length > 0) && (
                        <Section title="Next to it">
                            <div className="flex flex-wrap items-start gap-4">
                                {status.parents.map((parent) => (
                                    <Cylinder
                                        key={`parent:${parent.column}`}
                                        name={parent.table}
                                        note={`its ${parent.column} points there`}
                                        href={neighbourHref(parent)}
                                    />
                                ))}
                                <Cylinder
                                    name={previous ?? data.name}
                                    note="this table"
                                    href={null}
                                />
                                {status.children.map((child) => (
                                    <Cylinder
                                        key={`child:${child.table}.${child.column}`}
                                        name={child.table}
                                        note={`its ${child.column} points here`}
                                        href={neighbourHref(child)}
                                    />
                                ))}
                            </div>
                        </Section>
                    )}
            </form>
        </div>
    );
}
