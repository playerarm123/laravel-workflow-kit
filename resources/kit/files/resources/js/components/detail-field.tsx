import type { ReactNode } from 'react';

/**
 * One label/value row of a detail view, only ever inside a `<dl>`.
 */
export function DetailField({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="grid grid-cols-3 items-center gap-4 py-2">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="col-span-2 text-sm">{children}</dd>
        </div>
    );
}
