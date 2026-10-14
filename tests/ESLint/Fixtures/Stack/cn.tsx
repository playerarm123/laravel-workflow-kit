import { cn } from 'cn';
import { cn as merge } from '@/lib/utils';
import type { ClassValue } from "cn";
export { cn as join } from 'cn';
import { cnx } from 'cnx';

export async function Classes({ className }: { className?: string }) {
    const lazy = await import('cn');

    return <p className={merge(cn('a'), className, String(lazy), String(cnx))} />;
}

export type Value = ClassValue;
