import { useHttp } from '@inertiajs/react';
import { useId } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { entitiesOf } from '@/kit/method-form';
import type { StructureEndpoints, StructureGraph } from '@/kit/types';
import { Field, typeChoices } from '@/kit/vocabulary-form';

type StateFormData = {
    context: string;
    version: string;
    entity: string;
    previous: string | null;
    name: string;
    type: string;
};

/**
 * Adds a property to an entity's state, or changes one the code does not have yet. kit:apply
 * builds it as a private property the constructor promotes, a parameter of `reconstitute()` and a
 * getter of the same name; what `create()` starts it with is written by hand. A new property goes
 * last, after the ones the entity has. The server checks it and answers with the graph drawn again.
 */
export function StateForm({
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
    entity: string;
    previous: string | null;
    onSaved: (graph: StructureGraph) => void;
    onCancel: () => void;
}) {
    const manifest = graph.manifests[context];
    const form = useHttp<StateFormData, { graph: StructureGraph }>({
        context,
        version: graph.versions[context] ?? '',
        entity,
        previous,
        name: previous ?? '',
        type:
            previous === null
                ? ''
                : (manifest?.entities[entity]?.state[previous] ?? ''),
    });
    const { data, setData, errors, processing } = form;
    const typesId = useId();
    const aggregate =
        entitiesOf(manifest).find((candidate) => candidate.entity === entity)
            ?.aggregate ?? null;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            endpoints.saveState.replace(
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
                    ? `Add state to ${entity}`
                    : `Change ${entity}.${previous}`}
            </h2>
            <InputError
                message={errors.version ?? errors.context ?? errors.entity}
            />

            <Field label="Name" error={errors.name}>
                <Input
                    value={data.name}
                    placeholder="status"
                    onChange={(event) =>
                        setData({ ...data, name: event.target.value })
                    }
                    autoFocus
                />
            </Field>

            <Field label="Type" error={errors.type}>
                <datalist id={typesId}>
                    {typeChoices(graph, context, aggregate).map((type) => (
                        <option key={type} value={type} />
                    ))}
                </datalist>
                <Input
                    value={data.type}
                    list={typesId}
                    placeholder="CrateStatus, ?string, Shared/Money"
                    onChange={(event) =>
                        setData({ ...data, type: event.target.value })
                    }
                />
            </Field>

            <p className="text-xs text-muted-foreground">
                kit:apply writes the property, its place in reconstitute() and a
                getter of the same name. create() is left for you, because what
                a new {entity} starts with is a rule you decide.
            </p>

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
