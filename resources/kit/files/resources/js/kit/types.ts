/**
 * The graphs the structure screen draws, as StructureGraph::graph() builds them.
 *
 * @see App\Console\Commands\Structure\StructureGraph
 */
export type StructureStatus = 'done' | 'ready' | 'waiting' | 'differs';

export type StructureNodeKind =
    | 'context'
    | 'resource'
    | 'aggregate'
    | 'service'
    | 'port'
    | 'useCase'
    | 'model'
    | 'policy'
    | 'controller'
    | 'action'
    | 'page'
    | 'enum'
    | 'valueObject'
    | 'entity'
    | 'exception'
    | 'external';

export type StructureTarget = { view: 'context' | 'resource'; name: string };

export type StructureNodeData = {
    id: string;
    kind: StructureNodeKind;
    label: string;
    items: string[];
    status: StructureStatus | null;
    reason: string | null;
    command: string | null;
    target: StructureTarget | null;
    editable: boolean;
    variant: string | null;
};

export type StructureEdgeData = {
    id: string;
    source: string;
    target: string;
    label: string | null;
};

export type StructureView = {
    nodes: StructureNodeData[];
    edges: StructureEdgeData[];
};

export type StructureDifference = {
    check: string;
    subject: string;
    message: string;
    node: string | null;
};

export type StructureSection =
    | 'aggregates'
    | 'services'
    | 'ports'
    | 'useCases'
    | 'enums'
    | 'valueObjects'
    | 'exceptions';

export type EnumBacking = 'string' | 'int' | null;

export type EnumEntry = {
    aggregate: string | null;
    backing: EnumBacking;
    cases: Record<string, string | number | null>;
    transitions: Record<string, string[]> | null;
};

export type ValueObjectEntry = {
    aggregate: string | null;
    fields: Record<string, string>;
};

/**
 * The three kinds of exception a manifest designs (exceptions.md): an aggregate's refusal, an
 * invalid value, and a use case's refusal.
 */
export type ExceptionKind = 'refusal' | 'value' | 'application';

export type ExceptionEntry = {
    kind: ExceptionKind;
    aggregate: string | null;
    useCase: string | null;
};

/**
 * One method of an entity: its parameters in order, each to its type, and the exceptions it throws.
 */
export type EntityMethod = { params: Record<string, string>; throws: string[] };

/**
 * An entity's state is each property its constructor promotes, but for its id, to its type, in
 * the constructor's order. Each one has a getter of the same name.
 */
export type EntityEntry = {
    aggregate: string;
    state: Record<string, string>;
    behaviours: Partial<Record<string, EntityMethod>>;
    assertions: Partial<Record<string, EntityMethod>>;
};

/**
 * A context's manifest as it is on disk.
 *
 * @see App\Console\Commands\Structure\StructureFiles
 */
export type ContextManifest = {
    context: string;
    aggregates: Partial<
        Record<string, { children: string[]; repository: boolean }>
    >;
    services: Partial<
        Record<
            string,
            {
                shape: 'creates' | 'data' | 'plain';
                creates: string | null;
                repositories: string[];
                exception: boolean;
                replaces?: string | null;
            }
        >
    >;
    ports: Partial<
        Record<
            string,
            {
                layer: 'domain' | 'application';
                adapter: string | null;
                replaces?: string | null;
            }
        >
    >;
    useCases: Partial<
        Record<
            string,
            {
                shape: 'command-result' | 'command' | 'plain';
                returns: string;
                creates: boolean;
                query: boolean;
                repositories: string[];
                replaces?: string | null;
            }
        >
    >;
    enums: Partial<Record<string, EnumEntry>>;
    valueObjects: Partial<Record<string, ValueObjectEntry>>;
    exceptions: Partial<Record<string, ExceptionEntry>>;
    entities: Partial<Record<string, EntityEntry>>;
};

export type ResourceSection = 'controller' | 'actions' | 'pages';

/** The sections whose built pieces are changed by replacing them (structure.md). */
export type ReplaceableSection = 'ports' | 'useCases' | 'services';

export type PageKind = 'table' | 'grid' | 'form' | 'page';

/**
 * An HTTP resource's manifest as it is on disk.
 *
 * @see App\Console\Commands\Structure\StructureFiles
 */
export type ResourceManifest = {
    resource: string;
    model: string | null;
    controller: Partial<Record<string, string[]>>;
    actions: Partial<
        Record<string, { row: boolean; bulk: boolean; useCases: string[] }>
    >;
    policy: string[] | null;
    pages: Partial<Record<string, PageKind>>;
};

export type StructureGraph = {
    overview: StructureView;
    contexts: Partial<Record<string, StructureView>>;
    resources: Partial<Record<string, StructureView>>;
    byHand: StructureDifference[];
    manifests: Partial<Record<string, ContextManifest>>;
    versions: Partial<Record<string, string>>;
    resourceManifests: Partial<Record<string, ResourceManifest>>;
    resourceVersions: Partial<Record<string, string>>;
    resourceBuilt: Partial<Record<string, string[]>>;
    entityMethodsBuilt: Partial<Record<string, string[]>>;
    entityStateBuilt: Partial<Record<string, string[]>>;
    childrenBuilt: Partial<Record<string, string[]>>;
    outOfStep: Partial<Record<string, string[]>>;
    resourceOutOfStep: Partial<Record<string, string[]>>;
};

/**
 * Where the screen posts its changes. `__CONTEXT__` and `__RESOURCE__` stand for the context's or
 * the resource's name.
 *
 * @see App\Providers\KitServiceProvider
 */
export type StructureEndpoints = {
    createContext: string;
    savePiece: string;
    removePiece: string;
    replace: string;
    cancelReplacement: string;
    saveMethod: string;
    removeMethod: string;
    saveState: string;
    removeState: string;
    addChild: string;
    removeChild: string;
    syncPiece: string;
    createResource: string;
    saveResource: string;
    saveResourcePiece: string;
    removeResourcePiece: string;
    syncResourcePiece: string;
    graph: string;
    runCommand: string;
    docs: string;
    guide: string;
};

/**
 * A kit command the Run menu offers: what it is called, whether it writes files, and the views it
 * narrows to.
 *
 * @see Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\StructureCommands
 */
export type StructureCommand = {
    key: string;
    label: string;
    writes: boolean;
    scopes: ('context' | 'resource')[];
};

/**
 * What a command answered with: the line it stands for, its exit code and what it printed.
 */
export type StructureCommandRun = {
    command: string;
    exitCode: number;
    output: string;
};

export type StructurePayload = {
    graph: StructureGraph;
    endpoints: StructureEndpoints;
    commands: StructureCommand[];
};
