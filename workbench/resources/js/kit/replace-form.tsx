import { useHttp } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { IconAction } from '@/kit/panel-table';
import type {
    ReplaceableSection,
    StructureEndpoints,
    StructureGraph,
} from '@/kit/types';

/**
 * Starts replacing a built piece: a port's adapter with a new one, or a use case or a domain
 * service with a new one beside it. kit:apply builds the new piece and swaps it in; kit:retire
 * removes the old one once the tests pass.
 */
export function ReplaceForm({
    graph,
    endpoints,
    context,
    section,
    name,
    onSaved,
    onCancel,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    context: string;
    section: ReplaceableSection;
    name: string;
    onSaved: (graph: StructureGraph) => void;
    onCancel: () => void;
}) {
    const adapter = graph.manifests[context]?.ports[name]?.adapter ?? '';
    const form = useHttp<
        { version: string; section: string; name: string; replacement: string },
        { graph: StructureGraph }
    >({
        version: graph.versions[context] ?? '',
        section,
        name,
        replacement: section === 'ports' ? adapter : '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            endpoints.replace.replace(
                '__CONTEXT__',
                encodeURIComponent(context),
            ),
            {
                onSuccess: (response) => onSaved(response.graph),
            },
        );
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <h2 className="font-medium">
                Replace {section === 'ports' ? `the adapter of ${name}` : name}
            </h2>
            <p className="text-xs text-muted-foreground">
                The old one stays until <code>kit:retire</code> removes it, once
                the tests pass with the new one swapped in.
            </p>
            <InputError
                message={
                    form.errors.version ??
                    form.errors.section ??
                    form.errors.name
                }
            />
            <div className="space-y-1.5">
                <Label htmlFor="replacement">
                    {section === 'ports'
                        ? `New adapter, as Infra/{Folder}/{Prefix}${name}`
                        : section === 'services'
                          ? 'Name of the new service, without Service'
                          : name.startsWith('List')
                            ? 'Name of the new list, as List{Name}'
                            : 'Name of the new use case'}
                </Label>
                <Input
                    id="replacement"
                    value={form.data.replacement}
                    onChange={(event) =>
                        form.setData('replacement', event.target.value)
                    }
                    autoFocus
                />
                <InputError message={form.errors.replacement} />
            </div>
            <div className="flex gap-2">
                <Button type="submit" size="sm" disabled={form.processing}>
                    Replace
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
 * Stops a replacement that has not been retired. A refusal shows as a toast.
 */
export function CancelReplacementButton({
    graph,
    endpoints,
    context,
    section,
    name,
    onCancelled,
}: {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    context: string;
    section: ReplaceableSection;
    name: string;
    onCancelled: (graph: StructureGraph, message: string) => void;
}) {
    const version = graph.versions[context] ?? '';
    const form = useHttp<
        { version: string; section: string; name: string },
        { graph: StructureGraph }
    >({ version, section, name });

    const cancel = () => {
        form.transform((data) => ({ ...data, version }));
        form.post(
            endpoints.cancelReplacement.replace(
                '__CONTEXT__',
                encodeURIComponent(context),
            ),
            {
                onSuccess: (response) =>
                    onCancelled(
                        response.graph,
                        `Cancelled the replacement by ${name}`,
                    ),
                onError: (errors) =>
                    toast.error(
                        errors.name ??
                            errors.version ??
                            'The replacement could not be cancelled.',
                    ),
            },
        );
    };

    return (
        <IconAction
            icon={Undo2}
            label="Cancel replacement"
            disabled={form.processing}
            onClick={cancel}
        />
    );
}
