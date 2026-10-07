import { Form, useHttp } from '@inertiajs/react';
import type { ThingFormValues } from '@/types';

export function ThingForm({ defaults }: { defaults: ThingFormValues }) {
    const http = useHttp(defaults);

    const save = () => fetch('/things', { method: 'POST' });

    return (
        <Form action="/things" method="post" onSubmit={save} data-http={http}>
            <input name="name" defaultValue={defaults.name} />
        </Form>
    );
}
