import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export function FixtureTableToolbar() {
    const [search, setSearch] = useState('');

    useEffect(() => {
        setTimeout(() => router.reload(), 300);
    }, []);

    return <input value={search} onChange={(event) => setSearch(event.target.value)} />;
}
