# Application services

[ภาษาไทย](application-services.th.md)

An application service holds steps that two or more use-case handlers would otherwise repeat, when those steps are not a business rule. This guide follows one such step from the decision to share it, through the code the generator writes, to the handlers that call it and the tests. The rules themselves are in [layers.md](../resources/boost/guidelines/layers.md) (where a step goes, the two shapes), [handlers.md](../resources/boost/guidelines/handlers.md) (the handler that calls it), [exceptions.md](../resources/boost/guidelines/exceptions.md) (its refusal) and [testing.md](../resources/boost/guidelines/testing.md) (its test). This guide links to them rather than restating them.

The running example is a `Shipping` context with one aggregate, `Crate`, and a `Billing` context that holds the `Account` each crate is billed to. Two handlers, `CreateCrate` and `ReturnCrate`, both need the same answer: **the account of whoever is acting**.

## 1. Domain service, application service or handler

Put a step in the first place that can own it ([layers.md](../resources/boost/guidelines/layers.md), "Entity, domain service, application service or use-case handler").

| Where | Owns | Example in Shipping | Wrong home for it |
|---|---|---|---|
| A domain service | a business rule across several aggregates, or several instances of one, inside one context | "no two crates carry the same label" ([domain-services.md](domain-services.md)) | An application service: the rule would then hold only for the handlers that call it. |
| An application service | steps that are not a rule, which several handlers share: reading the actor, calling into another context, turning input into domain types | "the account of whoever is acting": it reads `UserContext` and loads an `Account` of `Billing` | A domain service: it may know neither the actor nor another context. A copy in each handler: the day one handler changes how it finds the account, the other keeps the old way. |
| The handler | the transaction, minting ids, the audit entry, side effects, and the order in which it calls services | opening the transaction, minting the crate's id, recording `crate.created` | The service: if it opened a transaction or recorded an entry, two services could not be combined in one use case. |

A step only one handler takes stays in that handler. Move it to a service when a second handler needs it.

## 2. Scaffold it

Never write a service by hand. The full signature ([layers.md](../resources/boost/guidelines/layers.md)):

```
php artisan make:application-service {Name} --domain={Context} (--data|--plain) [--repo={Aggregate}|--repo={Context}/{Aggregate}] [--actor] [--exception] [--force]
```

- `--data` and `--plain` pick the shape of `handle()`. Pass exactly one.
- `--repo=` injects one repository, of the service's own context (`Crate`) or of another (`Billing/Account`). It takes one repository: add a second one by hand.
- `--actor` injects `UserContext`, the actor port.
- `--exception` writes `{Name}Exception` beside the service, extending `ApplicationException`.
- `--force` writes the service again over one that exists.

For the account of whoever is acting:

```
php artisan make:application-service ResolvePayer --domain=Shipping --plain --actor --repo=Billing/Account --exception
```

This writes, in `app/Application/Shipping/Services/ResolvePayer/`:
- `ResolvePayerService.php`, with `UserContext` and `AccountRepository` in its constructor;
- `ResolvePayerException.php`;

and `tests/Feature/Application/Shipping/Services/ResolvePayer/ResolvePayerServiceTest.php`, in Feature because the service injects something only the container can build.

Until you write it, `handle()` reads:

```php
public function handle(): void
{
    throw new LogicException('ResolvePayerService::handle() is not implemented yet.');
}
```

It is the same placeholder a domain service starts with, and `kit:apply` waits on the same text before it swaps in a service that replaces another. Replace the whole line when you write the body.

## 3. The two shapes of `handle()`

### Plain: what it needs in, an entity, a list or nothing out

`--plain` writes `handle(): void`. Change the parameters and the return to what the service needs, with no `*Data` and no `*Result`.

```php
final class ResolvePayerService
{
    public function __construct(
        protected UserContext $user,
        protected AccountRepository $repo,
    ) {}

    /**
     * @throws ResolvePayerException
     */
    public function handle(): AccountEntity
    {
        $account = $this->repo->findByOwner($this->user->id());

        if ($account === null) {
            throw ResolvePayerException::noAccount($this->user->id());
        }

        return $account;
    }
}
```

### Data in, Result out: more than one value either way

Quoting a delivery needs the crate, the payer's account and the address, and hands back both a price and a date.

```php
final readonly class QuoteDeliveryData
{
    public function __construct(
        public string $crateId,
        public string $postcode,
    ) {}
}

/**
 * What the service hands back to the handler calling it.
 */
final readonly class QuoteDeliveryResult
{
    public function __construct(
        public Money $price,
        public CalendarDate $arrives,
    ) {}
}
```

Like a domain service's, its Data and Result are `final readonly` plain classes. There is no Creates shape: building a root needs a new id, and only the handler mints one.

## 4. The service's own exception

`--exception` writes `{Name}Exception`, which extends `ApplicationException` ([exceptions.md](../resources/boost/guidelines/exceptions.md)). Give it one code and one named constructor per reason:

```php
final class ResolvePayerException extends ApplicationException
{
    public const int NO_ACCOUNT = 101;

    public static function noAccount(string $ownerId): self
    {
        return new self(
            message: "No billing account belongs to [{$ownerId}].",
            code: self::NO_ACCOUNT,
            context: ['owner_id' => $ownerId],
        );
    }
}
```

The controller catches it by name, the way it catches a handler's refusal. That exception is the one thing of a service an entry point may name.

## 5. The handlers that call it

Each handler injects the service and calls it inside its own transaction. The service never opens one, never mints an id and never records the audit entry ([layers.md](../resources/boost/guidelines/layers.md), "Application service").

```php
final class CreateCrateHandler
{
    public function __construct(
        protected IdGenerator $ids,
        protected ResolvePayerService $payer,
        protected CrateRepository $crates,
        protected AuditLog $audit,
    ) {}

    public function __invoke(CreateCrateCommand $command): string
    {
        $id = $this->ids->next();

        return DB::transaction(function () use ($id, $command): string {
            $crate = CrateEntity::create($id, CrateLabel::from($command->label), $this->payer->handle()->id());
            $this->crates->save($crate);
            $this->audit->record('crate.created', $crate, ['label' => $crate->label()->value()]);

            return $id;
        });
    }
}
```

`ReturnCrateHandler` calls `$this->payer->handle()` the same way. A handler that injects a service which injects a repository counts as one that writes, so the transaction and the audit entry are not optional ([handlers.md](../resources/boost/guidelines/handlers.md)).

Never call the service from a controller, a command, a job or a listener (`layers:entry-points`), and never from another application service (`layers:app-service-calls`). When two services must run together, the handler calls both.

## 6. The tests

The suite follows what the service injects ([testing.md](../resources/boost/guidelines/testing.md)):

| The service | Test | How it gets the service |
|---|---|---|
| injects anything: a repository, `UserContext`, a port, a domain service | `tests/Feature/Application/{Context}/Services/{Name}/{Name}ServiceTest.php` | `app({Name}Service::class)`, with the actor bound through `UserContext` |
| injects nothing | `tests/Unit/Application/{Context}/Services/{Name}/{Name}ServiceTest.php` | `new {Name}Service`, with no Laravel |

The generator decides once. When a service written with nothing to inject later gets a constructor, move its test to the same path under `tests/Feature/`. `testing:mirror` names the move until you make it.

The first cases of `ResolvePayerServiceTest` cover the answer and the refusal:

```php
beforeEach(function () {
    $this->service = app(ResolvePayerService::class);
});

describe('ResolvePayerService', function () {
    it('finds the account of whoever is acting', function () {
        $owner = actingAsOwner();
        $account = billingAccountOf($owner);

        expect($this->service->handle()->id())->toBe($account->id());
    });

    it('refuses an actor with no account', function () {
        actingAsOwner();

        expect(fn () => $this->service->handle())->toThrow(ResolvePayerException::class);
    });
});
```

Then `CreateCrateHandlerTest` and `ReturnCrateHandlerTest` each cover their own happy path with its audit entry, and the refusal, after which nothing is written.

## 7. On the structure screen

Choose **Add ▸ Application service** in a context, under Application ([structure-screen.md](structure-screen.md)). The form takes the shape, whether it reads who is acting, whether it has its own exception and the repositories it injects, from any context. `kit:apply` then runs `make:application-service` with the matching flags. It passes `--repo` for the first repository of the service's own context, or the first of another when it has none of its own, so add any other repository to the constructor by hand. Until you do, `structure:matches` names it.

## Checklist

- [ ] Two or more handlers take the same steps, and the steps are not a business rule.
- [ ] Scaffolded with `make:application-service`, in one of the two shapes.
- [ ] `handle()` written, and the placeholder line gone.
- [ ] No transaction, no id, no audit entry, and no handler or other application service inside it.
- [ ] Its refusal is `{Name}Exception`, with a code and a named constructor per reason.
- [ ] Called only from handlers, inside their transaction, with an audit entry when it writes.
- [ ] Its test sits in `tests/Feature` when it injects anything, and covers the answer and the refusal.
