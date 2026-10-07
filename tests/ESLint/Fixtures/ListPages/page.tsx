import { router } from '@inertiajs/react';
import { useTable } from '@tanstack/react-table';
import { Table } from '@/components/ui/table';

export default function Index() {
    const options = {
        visit: (query: Record<string, string>) => {
            router.get('/rows', query);
        },
    };

    router.get('/rows');
    router.delete('/rows/1');

    return <Table data-options={options} data-table={useTable} />;
}
