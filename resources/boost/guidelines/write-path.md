# Write path

There is one way to change the database:

```
entry point (controller, command, job, listener) → {Verb}{Aggregate}Handler → repository → database
```

Only `App\Infra` writes to the database.

Enforced by the package's `tests/PHPStan/DatabaseWriteOutsideInfraRule.php`, which runs with `vendor/bin/phpstan` and is registered through the package's `tests/PHPStan/write-path.php`. It looks at the real type of what is called, so `$model->delete()` is caught and `$storage->delete()` is not. The rule itself is proven in the package's `tests/Architecture/WritePathRuleTest.php`.

## Kit files
These ship in the workflow kit package (`vendor/playerarm123/laravel-workflow-kit/`).
- *package:* `tests/PHPStan/DatabaseWriteOutsideInfraRule.php` and `tests/PHPStan/write-path.php`. Include the latter from `phpstan.neon` as `vendor/playerarm123/laravel-workflow-kit/tests/PHPStan/write-path.php`. It reads `rule-overrides.json` from the directory PHPStan runs in, the project root.
- *package:* `tests/Architecture/WritePathRuleTest.php` with `tests/PHPStan/Fixtures/WritePath/`

## Only the infrastructure writes
**Do** create, change and delete through a use-case handler. The handler loads or builds the aggregate, the aggregate applies its rules, and the repository writes (repositories.md).

**Don't**, anywhere outside `App\Infra`:
- call a writing method on an Eloquent model, an Eloquent or query builder, or a relation (`save`, `update`, `delete`, `create`, `insert`, `upsert`, `increment`, `touch`, `sync`, `attach`, …)
- call a writing static on a model (`Model::create()`, `Model::destroy()`, …)
- call `DB::statement()`/`insert()`/`update()`/`delete()`/`unprepared()`

Entry points may still read models, for example through route binding.

Seeders (`Database\Seeders`), factories (`Database\Factories`) and migrations may write.

**Why:** a write that skips the handler skips its transaction, its actor, the aggregate's rules and the audit log. The layer rule (layers.md) already keeps entry points away from the infrastructure; this closes the side door through the models themselves.

## Deleting
```
Delete{X}Handler:
  $x = $repo->getById($id);   // NotFound is explicit
  $x->assertCanBeDeleted();   // the business rule, when there is one
  $repo->delete($x);          // plus side effects (detach a media usage, …) in the same DB::transaction
```

**Do** keep the rule that forbids a delete in the entity (`LotteryDrawEntity::assertCanBeDeleted()`). The policy keeps its check too, but only to hide the button and answer 403 (authorization.md). It is not the place the rule lives, because two requests can pass the policy together.

**Do** catch the entity's refusal in the controller, by name, and answer with an error toast (exceptions.md). That covers the race the policy cannot see.

**Don't** delete in a controller, or open a transaction there.

## Exceptions
An exception is an entry in `rule-overrides.json` with `"rule": "write-path"`, `"check": "outside-infra"` and `"subject"` set to the class. Only the user may add one, with a reason. Changing the list re-runs the analysis, because the list is part of PHPStan's config.

Today's exceptions are the starter-kit code beside Fortify (`App\Actions\Fortify\ResetUserPassword` and the `Settings` controllers). Fortify in vendor already writes the auth columns of `users` (password, 2FA, passkeys, email verification), so that table has a second writer either way.
