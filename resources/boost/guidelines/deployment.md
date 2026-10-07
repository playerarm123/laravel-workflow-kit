# Deployment

Every project must deploy to **both** Plesk and Laravel Cloud. Write code that assumes neither.

Enforced by the package's `tests/Architecture/DeploymentTest.php` (`php artisan test --testsuite=Architecture`). The spec in `deploymentSpec()` is the machine-checked copy of this file: change the two together. Items marked *review only* cannot be read from the code, so a reviewer checks them.

## Targets
- **Plesk:** the Plesk extension for Laravel (Laravel Toolkit) is always installed, and it runs deploys, composer, artisan, the scheduler and the queue. Never set up cron entries or ssh scripts by hand. *Review only.*
- **Laravel Cloud:** configure everything through the Cloud environment.

## Storage
| | Plesk | Laravel Cloud |
|---|---|---|
| Allowed disks | local / public, or s3 | s3 or Laravel Cloud object storage (S3-compatible) only |

Because the same code runs on both targets:

**Do**
- Read and write every file through `Storage::disk(config('…'))`, so the disk name comes from config and env.
- Take every file url from `Storage::url()` on the server, and send it to the client as data.
- Keep `league/flysystem-aws-s3-v3` in `composer.json` `require` and an `s3` disk in `config/filesystems.php`, even when the project starts on a local disk.
- Read `filesystems.default` from `env('FILESYSTEM_DISK')`.

**Don't**
- Name a disk literally in `app/` (`Storage::disk('public')`). *Check `disk-literal`.*
- Build a local path for user files with `public_path()` or `storage_path()` in `app/`. They belong in `config/` only. *Check `local-path`.*
- Hardcode `/storage/` in PHP or JS. *Check `storage-url`.*

**Why:** Laravel Cloud has no persistent local disk, so anything written to the app server is gone after the next deploy. Code that never names a disk moves between the targets by changing env alone.

## Drivers switch between database and redis
**Do** keep `QUEUE_CONNECTION`, `CACHE_STORE` and `SESSION_DRIVER` set to `database` in `.env.example`. An environment that has Redis switches through its own env (see stack.md, Optional capabilities). *Check `drivers`.*

**Don't** use cache tags; the database driver cannot store them. *Check `cache-tags`.* **Don't** call the `Redis` facade from `app/`, because Redis is optional. *Check `redis-facade`.*

An environment that turns Redis on must have the `redis` PHP extension in its PHP handler. Plesk does not enable it by default. *Review only.*

## Long-running processes
- Scheduled work runs through `schedule:run` only, and the worker is `queue:work`.
- **Horizon** (optional) may replace `queue:work` only on a redis queue. Its process must be supervised on both targets, and the project's deploy notes must say how. *Check `horizon-redis`, plus review.*
- **Never laravel/octane.** It replaces PHP-FPM with a long-running server that Plesk does not host. *Check `forbidden`.*
- A realtime server you host yourself (e.g. Reverb) needs a supervised process on both targets, like Horizon. *Review only.*
- **Nightwatch agent** (`nightwatch:agent`): enable it through the Cloud integration on Laravel Cloud, and run it under a supervised process on Plesk. *Review only.* `.env.example` must list `NIGHTWATCH_TOKEN` with an empty value, so every environment knows to set it. *Check `env-keys`.*

## Library temporary files
- Laravel Excel: on Laravel Cloud, set `excel.temporary_files.remote_disk` to an s3 disk, because queued chunks may run on another instance.
- mPDF: set its `tempDir` in `config/`, never in `app/`.
- Image processing uses the GD driver, which both targets ship.

## Stepping outside this file
Use `rule-overrides.json` with `"rule": "deployment"`, exactly as stack.md describes. Only the user may add an entry.
