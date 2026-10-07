import { formatDateTime } from '@/lib/dates';

export function Stamp({ at, count }: { at: string; count: number }) {
    const long = new Intl.DateTimeFormat('th', { dateStyle: 'long' }).format(new Date(at));
    const short = Intl.DateTimeFormat('th').format(new Date(at));
    const Formatter = Intl.DateTimeFormat;
    const both = new Date(at).toLocaleString();
    const day = new Date(at).toLocaleDateString('th');
    const time = new Date(at).toLocaleTimeString('th');
    const total = count.toLocaleString();
    const pinned = formatDateTime(at, 'th', 'Asia/Bangkok');
    const money = new Intl.NumberFormat('th').format(count);

    return (
        <p>
            {long} {short} {String(Formatter)} {both} {day} {time} {total} {pinned} {money}
        </p>
    );
}
