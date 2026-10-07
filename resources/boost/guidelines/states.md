# States

A status that an entity changes declares, on its enum, where each case may go next. Every change asks that one place first:

```
{Root}Entity::{verb}()
  $this->assertCanBecome({X}Status::{Target})    if (! $this->status->canBecome($next)) throw {Root}…Exception
  $this->status = {X}Status::{Target}

enum {X}Status: string
  use HasTransitions                             canBecome(self $next): bool, isFinal(): bool
  transitions(): list<self>  =  match ($this) { every case => the cases it may become, [] when final }
```

Enforced by the package's `tests/Architecture/StatesTest.php` (`php artisan test --testsuite=Architecture`). The spec in `statesSpec()` is the machine-checked copy of this file: change the two together. Items marked *review only* cannot be read from the code, so a reviewer checks them.

## Kit files

These live at fixed paths. Copy them into a new project as they are. *Check `kit-files`.*
- `app/Domain/Shared/Concerns/HasTransitions.php`
- `tests/Unit/Domain/Shared/Concerns/HasTransitionsTest.php`

## What counts as a status

A status is an enum named `*Status` that an entity keeps in a property and changes after it is built, in any method but its constructor and its static factories (`create()`, `reconstitute()`). The check finds each one by reading the entities.

A status computed from other values, and a status an entity only sets when it is created, change nothing after the fact, so they declare no transitions.

## Declaring the transitions

```php
enum TransactionStatus: string
{
    use HasTransitions;

    case Pending = 'pending';
    case Held = 'held';
    case Settled = 'settled';
    case Cancelled = 'cancelled';

    /** @return list<self> */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Held, self::Settled, self::Cancelled],
            self::Held => [self::Settled, self::Cancelled],
            self::Settled, self::Cancelled => [],
        };
    }
}
```

**Do**
- Give every status `use HasTransitions` and a `transitions()` written as a `match ($this)` that names every case. A final case returns `[]`. *Check `declares`.*
- Scaffold one with `php artisan make:enum {Name} --domain={Context}/{Aggregate} --string --case=… --transition=Case:Next,Next`, one `--transition` per case that moves; a case none names is final. With `--transitions` alone every case starts at `[]`, for you to fill in. *Review only.*
- Read an end from `isFinal()`, never from a list of the final cases. *Review only.*

**Don't** list a case among its own next ones. Staying put is no change, so a request to move a row to the status it already has is refused, like any other move the enum does not list. *Check `self-loop`.*

**Why:** with the moves in one `match`, the whole diagram reads in one place, and a new case is a PHPStan error until it says where it goes. A rule written as "not this and not that" would let the new case slip through as an end without anyone deciding so.

## Changing a status

```php
public function settle(DateTimeImmutable $settledAt): void
{
    $this->assertCanBecome(TransactionStatus::Settled);

    $this->settledAt = $settledAt;
    $this->status = TransactionStatus::Settled;
}

private function assertCanBecome(TransactionStatus $next): void
{
    if (! $this->status->canBecome($next)) {
        throw WalletTransactionUnavailableException::cannotBecome($this, $next);
    }
}
```

**Do**
- Ask `canBecome()` before every assignment of a status, in the method itself or through an assertion of the same class. *Check `guarded`.*
- Refuse with a refusal of the aggregate (exceptions.md), which the controller catches by name. *Review only.*
- Skip, in a handler that changes many rows (actions.md), each row whose status `canBecome()` refuses, and count it as skipped. The handler asks the same question the entity asks, never a `!==` of its own. *Review only.*

**Don't**
- Write the moves as a chain of `if`s on the current status in the entity. *Review only.*
- Set a status from a parameter without asking (`changeStatus(XStatus $status)` that assigns it as it comes). *Check `guarded`.*

**Why:** the entity is the last thing that holds when two requests race (write-path.md). When it asks the enum, the policy, the bulk handler and the entity all read one diagram, and a row someone else already moved is refused instead of moved twice.

## Stepping outside this file

Use `rule-overrides.json` with `"rule": "states"`, exactly as stack.md describes. The `subject` is the enum for `declares` and `self-loop`, and `{Entity}::{method}` for `guarded`. Only the user may add an entry.
