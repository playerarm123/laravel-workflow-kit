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
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    PageKind,
    ResourceSection,
    StructureEndpoints,
    StructureGraph,
} from '@/kit/types';

export const RESOURCE_SECTION_LABELS: Record<ResourceSection, string> = {
    controller: 'method',
    actions: 'action',
    pages: 'page',
};

const PAGE_KINDS: PageKind[] = ['table', 'grid', 'form', 'page'];

/**
 * Every field any section posts, flat, so an error the editor names by field lands under it.
 */
type ResourcePieceFormData = {
    version: string;
    section: ResourceSection;
    previous: string | null;
    name: string;
    useCases: string[];
    row: boolean;
    bulk: boolean;
    kind: string;
};

type UseCaseChoice = { value: string; shape: string; returns: string };

/**
 * Every use case the context manifests list, as `Context/UseCase`, grouped by context.
 */
function choicesOfUseCases(
    graph: StructureGraph,
    keep: (choice: UseCaseChoice) => boolean,
): { group: string; choices: UseCaseChoice[] }[] {
    return Object.entries(graph.manifests)
        .map(([context, manifest]) => ({
            group: context,
            choices: Object.entries(manifest?.useCases ?? {})
                .flatMap(([name, entry]) =>
                    entry === undefined
                        ? []
                        : [
                              {
                                  value: `${context}/${name}`,
                                  shape: entry.shape,
                                  returns: entry.returns,
                              },
                          ],
                )
                .filter(keep),
        }))
        .filter((group) => group.choices.length > 0);
}

/**
 * The use cases a controller method may call, by the rule its name brings.
 */
function methodKeeps(method: string): (choice: UseCaseChoice) => boolean {
    switch (method) {
        case 'index':
            return (choice) => choice.shape === 'command-result';
        case 'store':
        case 'update':
            return (choice) => choice.shape !== 'plain';
        case 'destroy':
            return (choice) => choice.shape === 'plain';
        default:
            return () => true;
    }
}

function startingValues(
    graph: StructureGraph,
    resource: string,
    section: ResourceSection,
    previous: string | null,
): ResourcePieceFormData {
    const manifest = graph.resourceManifests[resource];
    const base: ResourcePieceFormData = {
        version: graph.resourceVersions[resource] ?? '',
        section,
        previous,
        name: previous ?? '',
        useCases: [],
        row: true,
        bulk: false,
        kind: 'table',
    };

    if (previous === null || manifest === undefined) {
        return base;
    }

    if (section === 'controller') {
        return { ...base, useCases: manifest.controller[previous] ?? [] };
    }

    if (section === 'actions') {
        return { ...base, ...manifest.actions[previous] };
    }

    return { ...base, kind: manifest.pages[previous] ?? 'page' };
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

/**
 * Adds a controller method, an action or a page to a resource, or changes one the code does not
 * have yet. The server checks the manifest's shape and its design rules.
 */
export function ResourcePieceForm({
    graph,
    endpoints,
    resource,
    section,
    previous,
    onSaved,
    onCancel,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    resource: string;
    section: ResourceSection;
    previous: string | null;
    onSaved: (graph: StructureGraph) => void;
    onCancel: () => void;
}) {
    const form = useHttp<ResourcePieceFormData, { graph: StructureGraph }>(
        startingValues(graph, resource, section, previous),
    );
    const { data, setData, errors, processing } = form;
    const folder =
        Object.keys(graph.resourceManifests[resource]?.pages ?? {})[0]?.replace(
            /\/[^/]+$/,
            '',
        ) ?? 'folder';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            endpoints.saveResourcePiece.replace(
                '__RESOURCE__',
                encodeURIComponent(resource),
            ),
            {
                onSuccess: (response) => onSaved(response.graph),
            },
        );
    };

    const toggle = (value: string, checked: boolean) =>
        setData(
            'useCases',
            checked
                ? [...data.useCases, value]
                : data.useCases.filter((item) => item !== value),
        );

    const actionChoices = choicesOfUseCases(graph, (choice) =>
        data.bulk
            ? choice.shape === 'command' && choice.returns === 'int'
            : choice.shape !== 'plain',
    );

    return (
        <form onSubmit={submit} className="space-y-4">
            <h2 className="font-medium">
                {previous === null
                    ? `Add a ${RESOURCE_SECTION_LABELS[section]} to ${resource}`
                    : `Change ${previous}`}
            </h2>
            <InputError message={errors.version ?? errors.section} />

            <Field
                label={
                    {
                        controller: 'Method name',
                        actions: 'Verb',
                        pages: 'Path under resources/js/pages',
                    }[section]
                }
                error={errors.name}
            >
                <Input
                    value={data.name}
                    placeholder={
                        {
                            controller: 'index',
                            actions: 'ChangeStatus',
                            pages: `${folder}/index`,
                        }[section]
                    }
                    onChange={(event) => setData('name', event.target.value)}
                    autoFocus
                />
            </Field>

            {section === 'controller' && (
                <Field label="Use cases it calls" error={errors.useCases}>
                    {choicesOfUseCases(graph, methodKeeps(data.name)).map(
                        (group) => (
                            <div key={group.group} className="space-y-1">
                                <div className="text-xs text-muted-foreground">
                                    {group.group}
                                </div>
                                {group.choices.map((choice) => (
                                    <Label
                                        key={choice.value}
                                        className="flex items-center gap-2 font-normal"
                                    >
                                        <Checkbox
                                            checked={data.useCases.includes(
                                                choice.value,
                                            )}
                                            onCheckedChange={(checked) =>
                                                toggle(
                                                    choice.value,
                                                    checked === true,
                                                )
                                            }
                                        />
                                        {choice.value.slice(
                                            group.group.length + 1,
                                        )}
                                    </Label>
                                ))}
                            </div>
                        ),
                    )}
                </Field>
            )}

            {section === 'actions' && (
                <>
                    <Field label="Acts on" error={errors.row ?? errors.bulk}>
                        <Label className="flex items-center gap-2 font-normal">
                            <Checkbox
                                checked={data.row}
                                onCheckedChange={(checked) =>
                                    setData('row', checked === true)
                                }
                            />
                            One row
                        </Label>
                        <Label className="flex items-center gap-2 font-normal">
                            <Checkbox
                                checked={data.bulk}
                                onCheckedChange={(checked) =>
                                    setData('bulk', checked === true)
                                }
                            />
                            A selection of rows
                        </Label>
                    </Field>
                    <Field label="Use case both call" error={errors.useCases}>
                        <Select
                            value={data.useCases[0] ?? ''}
                            onValueChange={(value) =>
                                setData('useCases', [value])
                            }
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Choose a use case" />
                            </SelectTrigger>
                            <SelectContent>
                                {actionChoices.map((group) => (
                                    <SelectGroup key={group.group}>
                                        <SelectLabel>{group.group}</SelectLabel>
                                        {group.choices.map((choice) => (
                                            <SelectItem
                                                key={choice.value}
                                                value={choice.value}
                                            >
                                                {choice.value.slice(
                                                    group.group.length + 1,
                                                )}
                                            </SelectItem>
                                        ))}
                                    </SelectGroup>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                </>
            )}

            {section === 'pages' && (
                <Field label="Kind" error={errors.kind}>
                    <Select
                        value={data.kind}
                        onValueChange={(kind) => setData('kind', kind)}
                    >
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {PAGE_KINDS.map((kind) => (
                                <SelectItem key={kind} value={kind}>
                                    {kind}
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
