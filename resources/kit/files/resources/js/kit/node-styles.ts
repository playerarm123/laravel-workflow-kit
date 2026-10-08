import {
    Box,
    Boxes,
    Cog,
    Database,
    ExternalLink,
    File,
    FileText,
    Gem,
    Globe,
    Layers,
    LayoutGrid,
    ListOrdered,
    MousePointerClick,
    Play,
    Plug,
    Route,
    Shield,
    Table,
    TriangleAlert,
    Workflow,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { StructureNodeData, StructureNodeKind } from '@/kit/types';

/**
 * The outline a card takes. Shapes follow what DDD and hexagonal diagrams use, so a reader knows
 * a card's kind before reading it: a port is a hexagon, a model a cylinder, a page a sheet.
 */
export type NodeShape =
    | 'box'
    | 'barred'
    | 'pill'
    | 'hexagon'
    | 'cylinder'
    | 'shield'
    | 'headed'
    | 'sheet'
    | 'double'
    | 'dashed'
    | 'tag'
    | 'rounded';

/**
 * How one kind of card looks. Colours follow Event Storming where it has one: an aggregate is
 * amber, a command blue, a read model green. `edge` paints the outline of the shapes cut with
 * clip-path, which loses an ordinary border; `bar` is a barred card's left edge and `band` a
 * headed card's header. Every class is written out whole, so Tailwind finds it.
 */
export type NodeStyle = {
    key: string;
    label: string;
    shape: NodeShape;
    icon: LucideIcon;
    border: string;
    fill: string;
    edge: string;
    accent: string;
    bar: string;
    band: string;
};

function style(
    key: string,
    label: string,
    shape: NodeShape,
    icon: LucideIcon,
    colours: Pick<
        NodeStyle,
        'border' | 'fill' | 'edge' | 'accent' | 'bar' | 'band'
    >,
): NodeStyle {
    return { key, label, shape, icon, ...colours };
}

const AMBER = {
    border: 'border-amber-300 dark:border-amber-500/60',
    fill: 'bg-amber-50 dark:bg-amber-950/50',
    edge: 'bg-amber-300 dark:bg-amber-500/60',
    accent: 'text-amber-600 dark:text-amber-300',
    bar: 'border-l-amber-500 dark:border-l-amber-400',
    band: 'bg-amber-100 dark:bg-amber-900/60',
};
const SKY = {
    border: 'border-sky-300 dark:border-sky-500/60',
    fill: 'bg-sky-50 dark:bg-sky-950/50',
    edge: 'bg-sky-300 dark:bg-sky-500/60',
    accent: 'text-sky-600 dark:text-sky-300',
    bar: 'border-l-sky-500 dark:border-l-sky-400',
    band: 'bg-sky-100 dark:bg-sky-900/60',
};
const EMERALD = {
    border: 'border-emerald-300 dark:border-emerald-500/60',
    fill: 'bg-emerald-50 dark:bg-emerald-950/50',
    edge: 'bg-emerald-300 dark:bg-emerald-500/60',
    accent: 'text-emerald-600 dark:text-emerald-300',
    bar: 'border-l-emerald-500 dark:border-l-emerald-400',
    band: 'bg-emerald-100 dark:bg-emerald-900/60',
};
const VIOLET = {
    border: 'border-violet-300 dark:border-violet-500/60',
    fill: 'bg-violet-50 dark:bg-violet-950/50',
    edge: 'bg-violet-300 dark:bg-violet-500/60',
    accent: 'text-violet-600 dark:text-violet-300',
    bar: 'border-l-violet-500 dark:border-l-violet-400',
    band: 'bg-violet-100 dark:bg-violet-900/60',
};
const TEAL = {
    border: 'border-teal-400 dark:border-teal-500/60',
    fill: 'bg-teal-50 dark:bg-teal-950',
    edge: 'bg-teal-400 dark:bg-teal-500/60',
    accent: 'text-teal-600 dark:text-teal-300',
    bar: 'border-l-teal-500 dark:border-l-teal-400',
    band: 'bg-teal-100 dark:bg-teal-900/60',
};
const SLATE = {
    border: 'border-slate-400 dark:border-slate-500',
    fill: 'bg-slate-50 dark:bg-slate-900',
    edge: 'bg-slate-400 dark:bg-slate-500',
    accent: 'text-slate-600 dark:text-slate-300',
    bar: 'border-l-slate-500 dark:border-l-slate-400',
    band: 'bg-slate-100 dark:bg-slate-900/60',
};
const ROSE = {
    border: 'border-rose-300 dark:border-rose-500/60',
    fill: 'bg-rose-50 dark:bg-rose-950/50',
    edge: 'bg-rose-300 dark:bg-rose-500/60',
    accent: 'text-rose-600 dark:text-rose-300',
    bar: 'border-l-rose-500 dark:border-l-rose-400',
    band: 'bg-rose-100 dark:bg-rose-900/60',
};
const INDIGO = {
    border: 'border-indigo-300 dark:border-indigo-500/60',
    fill: 'bg-indigo-50 dark:bg-indigo-950/50',
    edge: 'bg-indigo-300 dark:bg-indigo-500/60',
    accent: 'text-indigo-600 dark:text-indigo-300',
    bar: 'border-l-indigo-500 dark:border-l-indigo-400',
    band: 'bg-indigo-100 dark:bg-indigo-900/60',
};
const ORANGE = {
    border: 'border-orange-300 dark:border-orange-500/60',
    fill: 'bg-orange-50 dark:bg-orange-950/50',
    edge: 'bg-orange-300 dark:bg-orange-500/60',
    accent: 'text-orange-600 dark:text-orange-300',
    bar: 'border-l-orange-500 dark:border-l-orange-400',
    band: 'bg-orange-100 dark:bg-orange-900/60',
};
const ZINC = {
    border: 'border-zinc-400 dark:border-zinc-500',
    fill: 'bg-white dark:bg-zinc-900',
    edge: 'bg-zinc-400 dark:bg-zinc-500',
    accent: 'text-zinc-600 dark:text-zinc-300',
    bar: 'border-l-zinc-500 dark:border-l-zinc-400',
    band: 'bg-zinc-100 dark:bg-zinc-900/60',
};
const FUCHSIA = {
    border: 'border-fuchsia-300 dark:border-fuchsia-500/60',
    fill: 'bg-fuchsia-50 dark:bg-fuchsia-950/50',
    edge: 'bg-fuchsia-300 dark:bg-fuchsia-500/60',
    accent: 'text-fuchsia-600 dark:text-fuchsia-300',
    bar: 'border-l-fuchsia-500 dark:border-l-fuchsia-400',
    band: 'bg-fuchsia-100 dark:bg-fuchsia-900/60',
};
const LIME = {
    border: 'border-lime-400 dark:border-lime-500/60',
    fill: 'bg-lime-50 dark:bg-lime-950/50',
    edge: 'bg-lime-400 dark:bg-lime-500/60',
    accent: 'text-lime-700 dark:text-lime-300',
    bar: 'border-l-lime-500 dark:border-l-lime-400',
    band: 'bg-lime-100 dark:bg-lime-900/60',
};
const RED = {
    border: 'border-red-300 dark:border-red-500/60',
    fill: 'bg-red-50 dark:bg-red-950/50',
    edge: 'bg-red-300 dark:bg-red-500/60',
    accent: 'text-red-600 dark:text-red-300',
    bar: 'border-l-red-500 dark:border-l-red-400',
    band: 'bg-red-100 dark:bg-red-900/60',
};
const PLAIN = {
    border: 'border-foreground/50',
    fill: 'bg-card',
    edge: 'bg-foreground/50',
    accent: 'text-foreground',
    bar: 'border-l-foreground/60',
    band: 'bg-muted',
};
const MUTED = {
    border: 'border-muted-foreground/50',
    fill: 'bg-muted/40',
    edge: 'bg-muted-foreground/50',
    accent: 'text-muted-foreground',
    bar: 'border-l-foreground/60',
    band: 'bg-muted',
};

const KIND_STYLES: Record<StructureNodeKind, NodeStyle> = {
    context: style('context', 'Context', 'double', Layers, PLAIN),
    resource: style('resource', 'HTTP resource', 'box', Globe, INDIGO),
    aggregate: style('aggregate', 'Aggregate', 'barred', Boxes, AMBER),
    service: style('service', 'Domain service', 'pill', Cog, VIOLET),
    port: style('port', 'Port', 'hexagon', Plug, TEAL),
    useCase: style('useCase', 'Use case', 'box', Play, SKY),
    model: style('model', 'Model', 'cylinder', Database, SLATE),
    policy: style('policy', 'Policy', 'shield', Shield, ROSE),
    controller: style('controller', 'Controller', 'headed', Route, INDIGO),
    action: style('action', 'Action', 'pill', MousePointerClick, ORANGE),
    page: style('page', 'Page', 'sheet', File, ZINC),
    enum: style('enum', 'Enum', 'tag', ListOrdered, FUCHSIA),
    valueObject: style('valueObject', 'Value object', 'rounded', Gem, LIME),
    entity: style('entity', 'Entity', 'box', Box, AMBER),
    exception: style('exception', 'Exception', 'box', TriangleAlert, RED),
    external: style('external', 'Elsewhere', 'dashed', ExternalLink, MUTED),
};

const QUERY_STYLE = style('query', 'List use case', 'box', Table, EMERALD);

const STATUS_STYLE = style('status', 'Status', 'tag', Workflow, FUCHSIA);

const PAGE_ICONS: Partial<Record<string, LucideIcon>> = {
    table: Table,
    grid: LayoutGrid,
    form: FileText,
    page: File,
};

export const KIND_LABELS: Record<StructureNodeKind, string> =
    Object.fromEntries(
        Object.entries(KIND_STYLES).map(([kind, kindStyle]) => [
            kind,
            kindStyle.label,
        ]),
    ) as Record<StructureNodeKind, string>;

/**
 * How a card looks, from its kind and its variant: a list use case reads green, a status is an
 * enum whose icon shows it moves, a page's icon shows its kind.
 */
export function styleOf(
    node: Pick<StructureNodeData, 'kind' | 'variant'>,
): NodeStyle {
    if (node.kind === 'useCase' && node.variant === 'query') {
        return QUERY_STYLE;
    }

    if (node.kind === 'enum' && node.variant === 'status') {
        return STATUS_STYLE;
    }

    if (node.kind === 'page' && node.variant !== null) {
        return {
            ...KIND_STYLES.page,
            key: `page:${node.variant}`,
            label: `Page (${node.variant})`,
            icon: PAGE_ICONS[node.variant] ?? File,
        };
    }

    return KIND_STYLES[node.kind];
}

/**
 * The room a shape takes beyond its text: a cylinder's lid, a shield's point, a hexagon's tips.
 */
export const SHAPE_EXTRA_HEIGHT: Record<NodeShape, number> = {
    box: 0,
    barred: 0,
    pill: 4,
    hexagon: 4,
    cylinder: 18,
    shield: 14,
    headed: 6,
    sheet: 0,
    double: 4,
    dashed: 0,
    tag: 0,
    rounded: 0,
};
