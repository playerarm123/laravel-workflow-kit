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
    | 'applicationService'
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
    /** The aggregate node a card belongs to, which the screen frames together */
    group: string | null;
    /** What the piece is for, in the team's own words, as its manifest says */
    description: string | null;
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
    | 'applicationServices'
    | 'ports'
    | 'useCases'
    | 'enums'
    | 'valueObjects'
    | 'exceptions';

export type EnumBacking = 'string' | 'int' | null;

/**
 * What every manifest entry may say beside its own keys: what it is for, in the team's own words.
 * Only the manifest holds it, so the comparison with the code skips it.
 */
export type Described = { description?: string | null };

export type EnumEntry = Described & {
    aggregate: string | null;
    backing: EnumBacking;
    cases: Record<string, string | number | null>;
    transitions: Record<string, string[]> | null;
};

export type ValueObjectEntry = Described & {
    aggregate: string | null;
    fields: Record<string, string>;
};

/**
 * The three kinds of exception a manifest designs (exceptions.md): an aggregate's refusal, an
 * invalid value, and a use case's refusal.
 */
export type ExceptionKind = 'refusal' | 'value' | 'application';

export type ExceptionEntry = Described & {
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
export type EntityEntry = Described & {
    aggregate: string;
    state: Record<string, string>;
    behaviours: Partial<Record<string, EntityMethod>>;
    assertions: Partial<Record<string, EntityMethod>>;
    /** The table the entity is stored in, with its name; absent while it has none */
    table?: TableDesign;
};

/** What a delete of the parent does to a row that points at it (migrations.md). */
export type OnDelete = 'cascade' | 'restrict' | 'null' | 'noAction';

/**
 * A column of a designed table: the Blueprint method that builds it, with its size and flags, the
 * enum the model casts it to, and the comment above it.
 */
export type TableColumn = {
    type: string;
    length?: number;
    precision?: number;
    scale?: number;
    enum?: string;
    nullable?: boolean;
    unique?: boolean;
    index?: boolean;
    default?: string | number | boolean;
    comment?: string;
};

export type TableForeignKey = {
    table: string;
    onDelete?: OnDelete;
    nullable?: boolean;
    unique?: boolean;
    index?: boolean;
    comment?: string;
};

export type TableIndex = {
    columns: string[];
    unique: boolean;
    comment?: string;
};

/**
 * A table a manifest designs (structure.md, "Tables"): an entity's carries its `name`, one of a
 * context's `tables` is keyed by it and may say what it is for.
 */
export type TableDesign = Described & {
    name?: string;
    model: string | null;
    columns: Partial<Record<string, TableColumn>>;
    foreignKeys: Partial<Record<string, TableForeignKey>>;
    indexes: TableIndex[];
    timestamps: boolean;
    softDeletes: boolean;
};

/** The table an entity's state proposes, with each property it leaves out and why. */
export type ProposedTable = {
    table: TableDesign | null;
    unmapped: Partial<Record<string, string>>;
};

/**
 * A context's manifest as it is on disk.
 *
 * @see App\Console\Commands\Structure\StructureFiles
 */
export type ContextManifest = {
    context: string;
    /** What the context as a whole is for */
    description?: string;
    aggregates: Partial<
        Record<string, Described & { children: string[]; repository: boolean }>
    >;
    services: Partial<
        Record<
            string,
            Described & {
                shape: 'creates' | 'data' | 'plain';
                creates: string | null;
                repositories: string[];
                exception: boolean;
                replaces?: string | null;
            }
        >
    >;
    applicationServices: Partial<
        Record<
            string,
            Described & {
                shape: 'data' | 'plain';
                repositories: string[];
                actor: boolean;
                exception: boolean;
                replaces?: string | null;
            }
        >
    >;
    ports: Partial<
        Record<
            string,
            Described & {
                layer: 'domain' | 'application';
                adapter: string | null;
                replaces?: string | null;
            }
        >
    >;
    useCases: Partial<
        Record<
            string,
            Described & {
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
    /** The tables no entity maps to, by name */
    tables: Partial<Record<string, TableDesign>>;
};

export type ResourceSection = 'controller' | 'actions' | 'pages';

/** The sections whose built pieces are changed by replacing them (structure.md). */
export type ReplaceableSection =
    'ports' | 'useCases' | 'services' | 'applicationServices';

export type PageKind = 'table' | 'grid' | 'form' | 'dialog' | 'page';

/**
 * An HTTP resource's manifest as it is on disk.
 *
 * @see App\Console\Commands\Structure\StructureFiles
 */
export type ResourceManifest = {
    resource: string;
    /** What the resource is for */
    description?: string;
    model: string | null;
    controller: Partial<Record<string, string[]>>;
    actions: Partial<
        Record<string, { row: boolean; bulk: boolean; useCases: string[] }>
    >;
    policy: string[] | null;
    pages: Partial<Record<string, PageKind>>;
    /** The index page's main menu entry; null keeps it out; absent says nothing yet. */
    menu?: {
        title: string;
        icon: string;
        /** The key of the group it sits in, as Navigation.php declares it */
        group?: string;
        /** A translation key */
        description?: string;
        /** The policy's ability the entry asks; viewAny when absent */
        ability?: string;
    } | null;
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
    /** The groups app/Http/Navigation.php declares, which a menu entry may sit in */
    menuGroups: { key: string; title: string; icon: string }[];
    /** Each table a context designs, by context and name, with the status of the steps that build it */
    tables: Partial<
        Record<string, Partial<Record<string, StructureTableStatus>>>
    >;
};

export type StructureTableStatus = {
    /** The entity the table maps to, or null for one of the context's tables */
    entity: string | null;
    /** Whether the migrations build it already, which locks what they build */
    built: boolean;
    /** The columns and foreign keys the migrations build, which the page leaves as they are */
    builtColumns: string[];
    status: StructureStatus;
    reason: string | null;
    command: string | null;
    /**
     * What the database holds against what the migrations build: whether it could be read, why
     * not, and where the two differ; null while the migrations do not build the table
     */
    schema: {
        read: boolean;
        message: string | null;
        differences: string[];
    } | null;
    /** What the model the table names holds; null for a table no model maps to */
    model: {
        fillable: string[];
        casts: Partial<Record<string, string>>;
        relations: string[];
    } | null;
    /** The tables its foreign keys point at */
    parents: TableNeighbour[];
    /** The designed tables whose foreign keys point at it */
    children: TableNeighbour[];
};

/**
 * A table next to another in the ER diagram, by the foreign key column that ties them, with the
 * context and entity that design it; both null for one no manifest designs (`users`).
 */
export type TableNeighbour = {
    table: string;
    column: string;
    context: string | null;
    entity: string | null;
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
    saveDescription: string;
    saveTable: string;
    removeTable: string;
    /** `__ENTITY__` stands for the entity whose state proposes the table */
    proposedTable: string;
    createResource: string;
    saveResource: string;
    saveMenu: string;
    saveResourcePiece: string;
    removeResourcePiece: string;
    syncResourcePiece: string;
    saveResourceDescription: string;
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
