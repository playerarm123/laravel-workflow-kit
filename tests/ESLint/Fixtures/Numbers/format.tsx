import { formatPercent } from '@/lib/numbers';

export function Rate({ rate, count }: { rate: string; count: number }) {
    const money = new Intl.NumberFormat('th', { style: 'currency', currency: 'THB' }).format(count);
    const plain = Intl.NumberFormat('th').format(count);
    const Formatter = Intl.NumberFormat;
    const percent = `${(count / 100).toFixed(2)}%`;
    const fixed = Number(rate).toFixed(1);
    const pinned = formatPercent(rate, 'th');
    const day = new Intl.DateTimeFormat('th').format(new Date());

    return (
        <p>
            {money} {plain} {String(Formatter)} {percent} {fixed} {pinned} {day}
        </p>
    );
}
