import { useTable } from '@tanstack/react-table';
import { Table } from '@/components/ui/table';

export function FixtureLines() {
    return <Table data-table={useTable} />;
}
