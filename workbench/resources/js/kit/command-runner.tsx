import { useHttp } from '@inertiajs/react';
import {
    Archive,
    ChevronDown,
    CircleCheck,
    CircleX,
    Copy,
    FileSearch,
    Hammer,
    ListChecks,
    Play,
    RefreshCw,
    Terminal,
    Waypoints,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import type {
    StructureCommand,
    StructureCommandRun,
    StructureEndpoints,
    StructureTarget,
} from '@/kit/types';

const ICONS: Partial<Record<string, LucideIcon>> = {
    plan: ListChecks,
    apply: Hammer,
    'sync-preview': FileSearch,
    sync: RefreshCw,
    retire: Archive,
    routes: Waypoints,
};

/**
 * One run the screen asks for: the command, and the context or HTTP resource it narrows to, or
 * none for the whole project.
 */
export type CommandRequest = {
    command: StructureCommand;
    scope: StructureTarget | null;
};

/**
 * The line a run stands for, as a developer would type it, shown before it runs.
 */
function lineOf(request: CommandRequest): string {
    const options: Record<string, string> = {
        plan: 'kit:plan',
        apply: 'kit:apply',
        'sync-preview': 'kit:import --sync --dry-run',
        sync: 'kit:import --sync',
        retire: 'kit:retire',
        routes: 'wayfinder:generate --with-form',
    };
    const scope =
        request.scope === null
            ? ''
            : ` --${request.scope.view}=${request.scope.name}`;

    return `php artisan ${options[request.command.key] ?? request.command.key}${scope}`;
}

function CommandItem({
    request,
    onRun,
}: {
    request: CommandRequest;
    onRun: (request: CommandRequest) => void;
}) {
    const Icon = ICONS[request.command.key] ?? Terminal;

    return (
        <DropdownMenuItem onSelect={() => onRun(request)}>
            <Icon />
            {request.command.label}
        </DropdownMenuItem>
    );
}

/**
 * The header's Run menu: the kit's commands, first narrowed to the context or HTTP resource open
 * now, then for the whole project.
 */
export function RunMenu({
    commands,
    place,
    onRun,
}: {
    commands: StructureCommand[];
    place: StructureTarget | null;
    onRun: (request: CommandRequest) => void;
}) {
    if (commands.length === 0) {
        return null;
    }

    const here =
        place === null
            ? []
            : commands.filter((command) => command.scopes.includes(place.view));

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button size="sm" variant="outline">
                    <Play />
                    Run
                    <ChevronDown className="opacity-60" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                {place !== null && here.length > 0 && (
                    <>
                        <DropdownMenuGroup>
                            <DropdownMenuLabel className="text-xs text-muted-foreground">
                                {place.view === 'context'
                                    ? 'This context'
                                    : 'This HTTP resource'}{' '}
                                · {place.name}
                            </DropdownMenuLabel>
                            {here.map((command) => (
                                <CommandItem
                                    key={command.key}
                                    request={{ command, scope: place }}
                                    onRun={onRun}
                                />
                            ))}
                        </DropdownMenuGroup>
                        <DropdownMenuSeparator />
                    </>
                )}
                <DropdownMenuGroup>
                    <DropdownMenuLabel className="text-xs text-muted-foreground">
                        Everything
                    </DropdownMenuLabel>
                    {commands.map((command) => (
                        <CommandItem
                            key={command.key}
                            request={{ command, scope: null }}
                            onRun={onRun}
                        />
                    ))}
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Runs one command and shows what it printed. A command that only reads runs as the dialog opens;
 * one that writes files waits for Run. Once it has run, the screen draws the graph again.
 */
export function CommandDialog({
    request,
    endpoints,
    onClose,
    onFinished,
}: {
    request: CommandRequest;
    endpoints: StructureEndpoints;
    onClose: () => void;
    onFinished: (run: StructureCommandRun) => void;
}) {
    const form = useHttp<
        { context: string; resource: string },
        StructureCommandRun
    >({
        context: request.scope?.view === 'context' ? request.scope.name : '',
        resource: request.scope?.view === 'resource' ? request.scope.name : '',
    });
    const started = useRef(false);
    const run = form.response;
    const line = lineOf(request);

    const start = () => {
        started.current = true;
        form.post(
            endpoints.runCommand.replace(
                '__COMMAND__',
                encodeURIComponent(request.command.key),
            ),
            { onSuccess: (response) => onFinished(response) },
        );
    };

    useEffect(() => {
        if (!request.command.writes && !started.current) {
            start();
        }
        // The dialog runs a reading command once, as it opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        {request.command.label}
                        {run !== null &&
                            (run.exitCode === 0 ? (
                                <CircleCheck className="size-4 text-emerald-600" />
                            ) : (
                                <CircleX className="size-4 text-destructive" />
                            ))}
                    </DialogTitle>
                    <DialogDescription asChild>
                        <code className="block font-mono text-xs break-all">
                            {run?.command ?? line}
                        </code>
                    </DialogDescription>
                </DialogHeader>
                {run === null && !form.processing && request.command.writes && (
                    <p className="text-sm text-muted-foreground">
                        It writes files in the project. Git keeps what was there
                        before.
                        {request.command.key === 'retire' &&
                            ' It runs the test suites first, so it takes a while.'}
                    </p>
                )}
                {form.processing && (
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Spinner />
                        Running…
                    </div>
                )}
                <InputError
                    message={
                        form.errors.context ??
                        form.errors.resource ??
                        (form.errors as Partial<Record<string, string>>).command
                    }
                />
                {run !== null && (
                    <pre className="max-h-[60vh] overflow-auto rounded-md bg-muted p-3 font-mono text-xs whitespace-pre-wrap">
                        {run.output.trim() === '' ? '(no output)' : run.output}
                    </pre>
                )}
                <DialogFooter>
                    {run !== null && (
                        <Button
                            variant="ghost"
                            onClick={() =>
                                void navigator.clipboard
                                    .writeText(run.output)
                                    .then(() => toast.success('Copied'))
                            }
                        >
                            <Copy />
                            Copy output
                        </Button>
                    )}
                    <Button
                        variant={
                            run === null && request.command.writes
                                ? 'ghost'
                                : 'default'
                        }
                        disabled={form.processing}
                        onClick={onClose}
                    >
                        {run === null ? 'Cancel' : 'Close'}
                    </Button>
                    {run === null && request.command.writes && (
                        <Button disabled={form.processing} onClick={start}>
                            <Play />
                            Run
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
