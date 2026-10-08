import { useHttp } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { StructureEndpoints, StructureGraph } from '@/kit/types';

/**
 * Splits a comma-separated list into its trimmed, non-empty items.
 */
function listOf(text: string): string[] {
    return text
        .split(',')
        .map((item) => item.trim())
        .filter((item) => item !== '');
}

/**
 * Writes the empty manifest of a new HTTP resource, named after its controller.
 */
export function NewResourceForm({
    endpoints,
    onSaved,
    onCancel,
}: {
    endpoints: StructureEndpoints;
    onSaved: (graph: StructureGraph, name: string) => void;
    onCancel: () => void;
}) {
    const form = useHttp<
        { name: string; model: string | null },
        { graph: StructureGraph }
    >({ name: '', model: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(endpoints.createResource, {
            onSuccess: (response) => onSaved(response.graph, form.data.name),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <h2 className="font-medium">New HTTP resource</h2>
            <div className="space-y-1.5">
                <Label htmlFor="resource-name">
                    Name, as its controller is named without Controller
                </Label>
                <Input
                    id="resource-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData({
                            name: event.target.value,
                            model:
                                form.data.model === null
                                    ? null
                                    : event.target.value,
                        })
                    }
                    autoFocus
                />
                <InputError message={form.errors.name} />
            </div>
            <div className="space-y-1.5">
                <Label className="flex items-center gap-2 font-normal">
                    <Checkbox
                        checked={form.data.model === null}
                        onCheckedChange={(checked) =>
                            form.setData(
                                'model',
                                checked === true ? null : form.data.name,
                            )
                        }
                    />
                    It stands for no model, like a page of reports
                </Label>
                <InputError message={form.errors.model} />
            </div>
            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={form.processing}>
                    Create
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

/**
 * Sets the model a resource stands for and the abilities of its policy.
 */
export function ResourceSettingsForm({
    graph,
    endpoints,
    resource,
    onSaved,
    onCancel,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    resource: string;
    onSaved: (graph: StructureGraph) => void;
    onCancel: () => void;
}) {
    const manifest = graph.resourceManifests[resource];
    const form = useHttp<
        { version: string; model: string | null; policy: string[] | null },
        { graph: StructureGraph }
    >({
        version: graph.resourceVersions[resource] ?? '',
        model: manifest?.model ?? null,
        policy: manifest?.policy ?? null,
    });
    const { data, setData, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            endpoints.saveResource.replace(
                '__RESOURCE__',
                encodeURIComponent(resource),
            ),
            {
                onSuccess: (response) => onSaved(response.graph),
            },
        );
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <h2 className="font-medium">Settings of {resource}</h2>
            <InputError message={errors.version} />
            <div className="space-y-1.5">
                <Label htmlFor="resource-model">Model</Label>
                <Input
                    id="resource-model"
                    value={data.model ?? ''}
                    disabled={data.model === null}
                    onChange={(event) => setData('model', event.target.value)}
                />
                <Label className="flex items-center gap-2 font-normal">
                    <Checkbox
                        checked={data.model === null}
                        onCheckedChange={(checked) =>
                            setData('model', checked === true ? null : resource)
                        }
                    />
                    No model
                </Label>
                <InputError message={errors.model} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="resource-policy">
                    Policy abilities, separated by commas
                </Label>
                <Input
                    id="resource-policy"
                    defaultValue={(data.policy ?? []).join(', ')}
                    disabled={data.policy === null}
                    onChange={(event) =>
                        setData('policy', listOf(event.target.value))
                    }
                />
                <Label className="flex items-center gap-2 font-normal">
                    <Checkbox
                        checked={data.policy === null}
                        onCheckedChange={(checked) =>
                            setData('policy', checked === true ? null : [])
                        }
                    />
                    No policy
                </Label>
                <InputError message={errors.policy} />
            </div>
            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={form.processing}>
                    Save
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
