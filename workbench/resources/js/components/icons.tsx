import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';

export function sortIcon(state: false | 'asc' | 'desc') {
    if (state === 'asc') {
        return <ArrowUp className="size-3.5" />;
    }

    if (state === 'desc') {
        return <ArrowDown className="size-3.5" />;
    }

    return <ArrowUpDown className="size-3.5 opacity-50" />;
}
