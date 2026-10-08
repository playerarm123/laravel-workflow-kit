import { useHttp } from '@inertiajs/react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { StructureEndpoints, StructureGraph } from '@/kit/types';

/**
 * Writes the empty manifest of a new context.
 */
export function ContextForm({
    endpoints,
    onSaved,
    onCancel,
}: {
    endpoints: StructureEndpoints;
    onSaved: (graph: StructureGraph, name: string) => void;
    onCancel: () => void;
}) {
    const form = useHttp<{ name: string }, { graph: StructureGraph }>({
        name: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(endpoints.createContext, {
            onSuccess: (response) => onSaved(response.graph, form.data.name),
        });
    };

    return (
        <form onSubmit={submit} className="space-y-4">
            <h2 className="font-medium">New context</h2>
            <div className="space-y-1.5">
                <Label htmlFor="context-name">Name</Label>
                <Input
                    id="context-name"
                    value={form.data.name}
                    onChange={(event) =>
                        form.setData('name', event.target.value)
                    }
                    autoFocus
                />
                <InputError message={form.errors.name} />
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
