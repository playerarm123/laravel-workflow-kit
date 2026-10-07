import { Form, router, useForm } from '@inertiajs/react';
import { ThingForm } from '@/components/thing/form';
import type { ThingFormValues } from '@/types';

export default function Create({ defaults }: { defaults: ThingFormValues }) {
    const form = useForm(defaults);

    return (
        <>
            <ThingForm defaults={defaults} />
            <Form action="/things" method="post">
                <input name="name" defaultValue={form.data.name} />
            </Form>
            <button onClick={() => router.reload()} />
        </>
    );
}
