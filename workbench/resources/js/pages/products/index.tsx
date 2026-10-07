import { DataTable } from '@/components/dt-table';
import { useDataTable } from '@/hooks/use-data-table';

export default function Index() {
    const dt = useDataTable({ columns: [], rows: [], query: {}, visit: () => {} });

    return <DataTable dt={dt} />;
}
