import { Form, useHttp } from '@inertiajs/react';
import { FormDialog } from '@/components/form-dialog';
import type { ThingFormValues } from '@/types';

export function CreateThingDialog({ open }: { open: boolean }) {
    const http = useHttp({});

    const load = () => fetch('/things/create');

    return (
        <FormDialog<ThingFormValues> open={open} onOpenChange={load} title="New" load={null}>
            {() => (
                <Form action="/things" method="post" data-http={http}>
                    <input name="name" />
                </Form>
            )}
        </FormDialog>
    );
}
