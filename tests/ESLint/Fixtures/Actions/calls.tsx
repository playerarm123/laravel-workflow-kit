import { router } from '@inertiajs/react';
import { cancel, update } from '@/routes/things';

export function ThingActions({ id }: { id: string }) {
    const cancelThing = () => router.post(cancel.url(id), {}, { preserveScroll: true });
    const saveThing = () => router.patch(update.url(id), { name: 'x' });
    const replaceThing = () => router.put(update.url(id), { name: 'x' });
    const archiveThing = () => router.post('/things/archive', {});
    const removeThing = () => router.delete(`/things/${id}`);
    const listThings = () => router.get(cancel.url(id));

    return (
        <div>
            <button onClick={cancelThing} />
            <button onClick={saveThing} />
            <button onClick={replaceThing} />
            <button onClick={archiveThing} />
            <button onClick={removeThing} />
            <button onClick={listThings} />
        </div>
    );
}
