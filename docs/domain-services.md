# Domain services

[ภาษาไทย](domain-services.th.md)

A domain service holds a business rule that no single aggregate can hold. This guide follows one rule from the decision to use a service, through the code the generator writes, to the handler, the controller and the tests. The rules themselves are in [layers.md](../resources/boost/guidelines/layers.md) (where a rule goes, the three shapes), [handlers.md](../resources/boost/guidelines/handlers.md) (the handler that calls it), [exceptions.md](../resources/boost/guidelines/exceptions.md) (its refusal) and [testing.md](../resources/boost/guidelines/testing.md) (its test). This guide links to them rather than restating them.

The running example is a `Shipping` context with one aggregate, `Crate`. A crate carries a `CrateLabel` value object, and the rule is: **no two crates carry the same label**.

## 1. Entity, domain service or handler

Put a rule in the first place that can own it ([layers.md](../resources/boost/guidelines/layers.md), "Entity, domain service or application service").

| Where | Owns | Example in Shipping | Wrong home for it |
|---|---|---|---|
| The entity | a rule that needs one aggregate's own state | "a sealed crate cannot be relabelled": `CrateEntity::relabel()` asks its own status | A service that loads the crate only to check its status: the rule then holds only for callers that go through the service. |
| A domain service | a rule across several aggregates, or several instances of one, inside one context | "no two crates carry the same label": only something that can look at the other crates can say no | The entity: `CrateEntity` sees itself and no other crate. The handler: a second handler that creates crates (an import, a copy) would have to repeat the check, and the one that forgets it breaks the rule. |
| The handler | everything that is not a business rule: the transaction, who is acting, minting ids, other contexts, side effects, the audit log | opening the transaction, minting the crate's id, recording `crate.created` | The service: it never opens a transaction, never knows who is acting and never calls another context. If it did, two services could not be combined in one use case. |

A rule that needs another context (for example "a crate may be opened only while the customer's account in `Billing` is active") is not a domain service either. The handler asks `Billing` and passes the answer in.

## 2. Scaffold it

Never write a service by hand. The full signature ([layers.md](../resources/boost/guidelines/layers.md)):

```
php artisan make:domain-service {Name} --domain={Context} (--creates={Aggregate}|--data|--plain) [--repo={Aggregate}] [--exception] [--force]
```

- `--creates`, `--data` and `--plain` pick the shape of `handle()`. Pass exactly one.
- `--repo={Aggregate}` injects `{Aggregate}Repository` of the same context into the constructor, and puts the test in `tests/Feature`. It takes one repository: add a second one by hand.
- `--exception` writes `{Name}Exception` beside the service.
- `--force` writes the service again over one that exists.

For the label rule:

```
php artisan make:domain-service OpenCrate --domain=Shipping --creates=Crate --repo=Crate --exception
```

This writes, in `app/Domain/Shipping/Services/OpenCrate/`:
- `OpenCrateService.php`, with the repository in its constructor;
- `OpenCrateData.php`, the input of the Creates shape;
- `OpenCrateException.php`;

and `tests/Feature/Domain/Shipping/Services/OpenCrate/OpenCrateServiceTest.php`.

Until you write it, `handle()` reads:

```php
public function handle(string $id, OpenCrateData $data): CrateEntity
{
    throw new LogicException('OpenCrateService::handle() is not implemented yet.');
}
```

That one throw is the only `LogicException` the domain may hold. `exceptions:domain-throws` lets it stand, and `kit:apply` waits on the same text before it swaps in a service that replaces another. Replace the whole line when you write the body, and never reuse the text for anything else.

## 3. The three shapes of `handle()`

### Creates: the service builds a new aggregate

The handler mints the id and hands it in. The service checks the rule, builds the root through its own `create()` and saves it.

```php
final class OpenCrateService
{
    public function __construct(
        protected CrateRepository $repo,
    ) {}

    /**
     * @throws OpenCrateException
     */
    public function handle(string $id, OpenCrateData $data): CrateEntity
    {
        if ($this->repo->existsWithLabel($data->label)) {
            throw OpenCrateException::labelTaken($data->label);
        }

        $crate = CrateEntity::create($id, $data->label);
        $this->repo->save($crate);

        return $crate;
    }
}
```

```php
/**
 * What the service is handed, already in domain types.
 */
final readonly class OpenCrateData
{
    public function __construct(
        public CrateLabel $label,
    ) {}
}
```

`existsWithLabel()` is a finder the repository interface declares for the service. A finder returns an entity or a scalar the domain needs ([repositories.md](../resources/boost/guidelines/repositories.md)):

```php
#[Override]
public function existsWithLabel(CrateLabel $label): bool
{
    return $this->guardRead(fn (): bool => $this->newQuery()->where('label', $label->value())->exists());
}
```

### Plain: an id or two in, an entity, a list or nothing out

Relabelling a crate keeps the same rule, so it needs the service too. The entity still owns its own part ("not once sealed").

```php
final class RelabelCrateService
{
    public function __construct(
        protected CrateRepository $repo,
    ) {}

    /**
     * @throws RelabelCrateException
     * @throws CrateSealedException
     */
    public function handle(CrateEntity $crate, CrateLabel $label): void
    {
        if (! $crate->label()->equals($label) && $this->repo->existsWithLabel($label)) {
            throw RelabelCrateException::labelTaken($label);
        }

        $crate->relabel($label);
        $this->repo->update($crate);
    }
}
```

`--plain` writes `handle(): void`. Change the parameters and the return to what the service needs, with no `*Data` and no `*Result`.

### Data in, Result out: more than one value either way

Swapping the labels of two crates reads two instances and writes both.

```php
final readonly class SwapCrateLabelsData
{
    public function __construct(
        public string $firstCrateId,
        public string $secondCrateId,
    ) {}
}

/**
 * What the service hands back: the aggregates it wrote, so the caller need not read them again.
 */
final readonly class SwapCrateLabelsResult
{
    public function __construct(
        public CrateEntity $first,
        public CrateEntity $second,
    ) {}
}
```

A service's Data and Result are `final readonly` plain classes in domain types. They never extend `Spatie\LaravelData\Data` the way a handler's Command does, because the domain uses no framework and no package (`layers:domain-framework`).

## 4. The service's own exception

`--exception` writes `{Name}Exception`, which extends `DomainException` directly. It is the one refusal that does not extend the context's `{Context}DomainException` ([exceptions.md](../resources/boost/guidelines/exceptions.md)). Give it one code and one named constructor per reason, and build the context there:

```php
final class OpenCrateException extends DomainException
{
    public const int LABEL_TAKEN = 101;

    public static function labelTaken(CrateLabel $label): self
    {
        return new self(
            message: "Another crate already carries the label [{$label->value()}].",
            code: self::LABEL_TAKEN,
            context: ['label' => $label->value()],
        );
    }
}
```

A rule the entity can check on its own throws the entity's exception instead (`CrateSealedException` above).

## 5. The handler that calls it

The handler does what the service never does: it mints the id, opens the transaction and records the audit entry ([handlers.md](../resources/boost/guidelines/handlers.md), [audit-log.md](../resources/boost/guidelines/audit-log.md)). A handler that injects a service with a repository counts as one that writes, so the transaction and the audit entry are not optional.

```
php artisan make:use-case OpenCrate --domain=Shipping --command --creates
```

```php
final class OpenCrateHandler
{
    public function __construct(
        protected IdGenerator $ids,
        protected OpenCrateService $openCrate,
        protected AuditLog $audit,
    ) {}

    public function __invoke(OpenCrateCommand $command): string
    {
        $id = $this->ids->next();

        return DB::transaction(function () use ($id, $command): string {
            $crate = $this->openCrate->handle($id, new OpenCrateData(label: CrateLabel::from($command->label)));
            $this->audit->record('crate.created', $crate, ['label' => $crate->label()->value()]);

            return $id;
        });
    }
}
```

The check reads the other crates and the write follows inside one transaction. Two requests can still pass the check together, so give the column a unique index as well ([migrations.md](../resources/boost/guidelines/migrations.md)). The service gives the user an answer they can act on, and the database is the last guard.

## 6. The controller that catches the refusal

The controller catches the service's exception by name and answers with an error toast ([exceptions.md](../resources/boost/guidelines/exceptions.md), "Refusals in a controller"). Anything else reaches `ExceptionResponses`.

```php
public function store(StoreCrateRequest $request, OpenCrateHandler $openCrate): RedirectResponse
{
    try {
        $id = $openCrate($request->toCommand());
    } catch (OpenCrateException) {
        Inertia::flash(FlashToast::KEY, FlashToast::error(__('crates.label_taken')));

        return back();
    }

    Inertia::flash(FlashToast::KEY, FlashToast::success(__('crates.created')));

    return to_route('crates.show', $id);
}
```

Never call the service from the controller. An entry point may use from the domain only enums, value objects and exceptions (`layers:entry-points`).

## 7. The tests

The suite follows what the service reaches ([testing.md](../resources/boost/guidelines/testing.md)):

| The service | Test | How it gets the service |
|---|---|---|
| injects a repository, directly or through another service | `tests/Feature/Domain/{Context}/Services/{Name}/{Name}ServiceTest.php` | `app({Name}Service::class)`, on the real database |
| only computes | `tests/Unit/Domain/{Context}/Services/{Name}/{Name}ServiceTest.php` | `new {Name}Service(…)`, with no Laravel |

The generator decides once, from `--repo`. When a service written without `--repo` later reaches a repository, directly or through another service, move its test to the same path under `tests/Feature/` and resolve the service from the container. `testing:mirror` names the move until you make it.

The first cases of `OpenCrateServiceTest` cover the rule both ways and read the effect back through the repository:

```php
beforeEach(function () {
    $this->service = app(OpenCrateService::class);
});

describe('OpenCrateService', function () {
    it('opens a crate under a label no other crate carries', function () {
        $id = fake()->uuid();

        $this->service->handle($id, new OpenCrateData(label: CrateLabel::from('A-01')));

        expect(app(CrateRepository::class)->getById($id)->label()->value())->toBe('A-01');
    });

    it('refuses a label another crate carries', function () {
        $this->service->handle(fake()->uuid(), new OpenCrateData(label: CrateLabel::from('A-01')));

        expect(fn () => $this->service->handle(fake()->uuid(), new OpenCrateData(label: CrateLabel::from('A-01'))))
            ->toThrow(OpenCrateException::class);
    });
});
```

Then:
- `OpenCrateHandlerTest` covers the happy path with its audit entry (`assertDatabaseHas('audit_entries', ['event' => 'crate.created', 'subject_id' => $id])`) and the refusal, after which no crate and no entry are written;
- `CrateController/StoreTest` covers the toast for the refusal (`assertInertiaFlash('toast.type', 'error')`) beside its guest, 403 and validation cases.

## 8. On the structure screen

Choose **Add ▸ Domain service** in a context ([structure-screen.md](structure-screen.md)). The form takes the shape, the aggregate a Creates service builds, whether it has its own exception and the repositories it injects. `kit:apply` then runs `make:domain-service` with the matching flags. It passes `--repo` for the first repository of the service's own context, whatever the shape, so add any other repository to the constructor by hand. Until you do, `structure:matches` names it.

## Checklist

- [ ] The rule spans several aggregates, or several instances of one, inside one context.
- [ ] Scaffolded with `make:domain-service`, in one of the three shapes.
- [ ] `handle()` written, and the placeholder line gone.
- [ ] Data and Result are plain `final readonly` classes in domain types.
- [ ] Its refusal is `{Name}Exception`, with a code and a named constructor per reason.
- [ ] Called only from a handler (or another service of the same context), inside the handler's transaction, with an audit entry.
- [ ] The controller catches the refusal by name.
- [ ] Its test sits in `tests/Feature` when it reaches a repository, and covers the rule both ways.
