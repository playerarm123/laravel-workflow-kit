import {
    BookOpen,
    ChevronDown,
    CircleHelp,
    Eye,
    FileText,
    Plus,
    Settings2,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { RunMenu } from '@/kit/command-runner';
import type { CommandRequest } from '@/kit/command-runner';
import { styleOf } from '@/kit/node-styles';
import type { Editing } from '@/kit/panel-sections';
import { IconAction } from '@/kit/panel-table';
import type {
    ResourceSection,
    StructureCommand,
    StructureEndpoints,
    StructureNodeKind,
    StructureSection,
    StructureTarget,
} from '@/kit/types';

/**
 * One switch of the View menu: a kind of card the context view may leave out.
 */
export type ViewSwitch = {
    label: string;
    icon: StructureNodeKind;
    on: boolean;
    set: (on: boolean) => void;
};

/**
 * One entry of an Add menu: what it opens, and the card kind whose icon it wears.
 */
type AddItem = { label: string; icon: StructureNodeKind; editing: Editing };

function iconOf(kind: StructureNodeKind): LucideIcon {
    return styleOf({ kind, variant: null }).icon;
}

/**
 * A context's Add menu, grouped the way the layers read: the domain's own pieces, the words they
 * speak in, then the application around them. The shared kernel holds only vocabulary.
 */
function contextGroups(
    context: string,
    shared: boolean,
): { label: string; items: AddItem[] }[] {
    const piece = (
        section: StructureSection,
        label: string,
        icon: StructureNodeKind,
    ): AddItem => ({
        label,
        icon,
        editing: { kind: 'piece', context, section, previous: null },
    });

    if (shared) {
        return [
            {
                label: 'Shared kernel',
                items: [
                    piece('enums', 'Enum', 'enum'),
                    piece('valueObjects', 'Value object', 'valueObject'),
                    piece('exceptions', 'Invalid value', 'exception'),
                ],
            },
        ];
    }

    return [
        {
            label: 'Domain',
            items: [
                piece('aggregates', 'Aggregate', 'aggregate'),
                {
                    label: 'Entity method',
                    icon: 'entity',
                    editing: {
                        kind: 'method',
                        context,
                        entity: null,
                        previous: null,
                    },
                },
                piece('services', 'Domain service', 'service'),
                piece('exceptions', 'Exception', 'exception'),
            ],
        },
        {
            label: 'Vocabulary',
            items: [
                piece('enums', 'Enum', 'enum'),
                piece('valueObjects', 'Value object', 'valueObject'),
            ],
        },
        {
            label: 'Application',
            items: [
                piece('useCases', 'Use case', 'useCase'),
                piece('ports', 'Port', 'port'),
            ],
        },
    ];
}

function resourceItems(resource: string): AddItem[] {
    const piece = (
        section: ResourceSection,
        label: string,
        icon: StructureNodeKind,
    ): AddItem => ({
        label,
        icon,
        editing: { kind: 'resource-piece', resource, section, previous: null },
    });

    return [
        piece('controller', 'Controller method', 'controller'),
        piece('actions', 'Action', 'action'),
        piece('pages', 'Page', 'page'),
    ];
}

function MenuItems({
    items,
    onEdit,
}: {
    items: AddItem[];
    onEdit: (editing: Editing) => void;
}) {
    return items.map((item) => {
        const Icon = iconOf(item.icon);

        return (
            <DropdownMenuItem
                key={item.label}
                onSelect={() => onEdit(item.editing)}
            >
                <Icon
                    className={
                        styleOf({ kind: item.icon, variant: null }).accent
                    }
                />
                {item.label}
            </DropdownMenuItem>
        );
    });
}

/**
 * The header of the structure screen: where you are, and what you can add or show here, each in
 * its own menu so the row stays short however much a view offers.
 */
export function StructureToolbar({
    place,
    shared,
    endpoints,
    switches,
    commands,
    onEdit,
    onRun,
}: {
    place: StructureTarget | null;
    shared: boolean;
    endpoints: StructureEndpoints;
    switches: ViewSwitch[];
    commands: StructureCommand[];
    onEdit: (editing: Editing) => void;
    onRun: (request: CommandRequest) => void;
}) {
    const context = place?.view === 'context' ? place.name : null;
    const resource = place?.view === 'resource' ? place.name : null;

    return (
        <header className="flex items-center justify-between gap-4 border-b px-4 py-2">
            <Breadcrumb>
                <BreadcrumbList>
                    <BreadcrumbItem>
                        {place === null ? (
                            <BreadcrumbPage>Structure</BreadcrumbPage>
                        ) : (
                            <BreadcrumbLink href="#">Structure</BreadcrumbLink>
                        )}
                    </BreadcrumbItem>
                    {place !== null && (
                        <>
                            <BreadcrumbSeparator />
                            <BreadcrumbItem>
                                <BreadcrumbPage>
                                    {place.view === 'context'
                                        ? 'Context'
                                        : 'HTTP resource'}{' '}
                                    {place.name}
                                </BreadcrumbPage>
                            </BreadcrumbItem>
                        </>
                    )}
                </BreadcrumbList>
            </Breadcrumb>
            <div className="flex items-center gap-1">
                {place === null && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button size="sm">
                                <Plus />
                                New
                                <ChevronDown className="opacity-60" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-52">
                            <MenuItems
                                items={[
                                    {
                                        label: 'Context',
                                        icon: 'aggregate',
                                        editing: { kind: 'context' },
                                    },
                                    {
                                        label: 'HTTP resource',
                                        icon: 'controller',
                                        editing: { kind: 'resource' },
                                    },
                                ]}
                                onEdit={onEdit}
                            />
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
                {context !== null && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button size="sm">
                                <Plus />
                                Add
                                <ChevronDown className="opacity-60" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-56">
                            {contextGroups(context, shared).map(
                                (group, index) => (
                                    <DropdownMenuGroup key={group.label}>
                                        {index > 0 && <DropdownMenuSeparator />}
                                        <DropdownMenuLabel className="text-xs text-muted-foreground">
                                            {group.label}
                                        </DropdownMenuLabel>
                                        <MenuItems
                                            items={group.items}
                                            onEdit={onEdit}
                                        />
                                    </DropdownMenuGroup>
                                ),
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
                {resource !== null && (
                    <>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button size="sm">
                                    <Plus />
                                    Add
                                    <ChevronDown className="opacity-60" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                <MenuItems
                                    items={resourceItems(resource)}
                                    onEdit={onEdit}
                                />
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <IconAction
                            icon={Settings2}
                            label="Model and policy"
                            className="size-8"
                            onClick={() =>
                                onEdit({ kind: 'resource-settings', resource })
                            }
                        />
                    </>
                )}
                <RunMenu commands={commands} place={place} onRun={onRun} />
                {switches.length > 0 && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button size="sm" variant="outline">
                                <Eye />
                                View
                                <ChevronDown className="opacity-60" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-52">
                            <DropdownMenuLabel className="text-xs text-muted-foreground">
                                Show on the canvas
                            </DropdownMenuLabel>
                            {switches.map((viewSwitch) => {
                                const Icon = iconOf(viewSwitch.icon);

                                return (
                                    <DropdownMenuCheckboxItem
                                        key={viewSwitch.label}
                                        checked={viewSwitch.on}
                                        onSelect={(event) =>
                                            event.preventDefault()
                                        }
                                        onCheckedChange={(on) =>
                                            viewSwitch.set(on)
                                        }
                                    >
                                        <Icon
                                            className={
                                                styleOf({
                                                    kind: viewSwitch.icon,
                                                    variant: null,
                                                }).accent
                                            }
                                        />
                                        {viewSwitch.label}
                                    </DropdownMenuCheckboxItem>
                                );
                            })}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-8 text-muted-foreground"
                            aria-label="Help"
                        >
                            <CircleHelp />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem asChild>
                            <a href={endpoints.guide}>
                                <BookOpen />
                                Guide
                            </a>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <a href={endpoints.docs}>
                                <FileText />
                                Docs
                            </a>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}
