# Image library (the media feature)

This file applies only to a project that installed the feature: `php artisan kit:install --with=media`, which `.kit/features.json` records. A project without it has no image library and nothing here holds.

The library keeps the images the app shows, such as profile photos, in folders, and hands them to the forms that use one. An image something uses cannot be deleted:

```
upload  → StoreMediaItemRequest → UploadMediaItemsHandler
            → MediaStorage::store()  (resized webp + square thumbnail, EXIF gone)
            → DB::transaction { MediaQuota::assertRoomFor(), saveMany(), audit }
            → on any failure, the written files are removed again
use     → a form's MediaPickerField posts {x}_media_item_id
            → {Verb}{Aggregate}Handler, in its transaction: attachUsage() / detachUsage()
delete  → DeleteMediaItemHandler → assertNotInUse(usagesOf()) → delete row → delete files
```

Enforced by the package's `tests/Architecture/MediaTest.php` (`php artisan test --testsuite=Architecture`), which runs only where the feature is installed. The spec in `mediaSpec()` is the machine-checked copy of this file: change the two together. Items marked *review only* cannot be read from the code, so a reviewer checks them.

The library is not split by tenant yet: every signed-in user the policies let in sees one library. A project that needs one library per tenant does not install the feature until the kit offers a way.

## Kit files

`kit:install --with=media` writes them from `resources/kit/features/media/` in the kit, and every later `kit:install` keeps them up to date. *Check `kit-files`* fails when one is missing or, under `files/`, differs from the kit's copy.

- under `files/`, kept as the kit ships them: the `Media` context (`app/Domain/Media`, `app/Application/Media`), its adapters (`app/Infra/Media`, `EloquentMediaItemRepository`, `EloquentMediaFolderRepository`, `EloquentListMediaItemsQuery`, the two `{X}LogPayload`), the models `MediaItem`, `MediaFolder` and `MediaUsage` with their factories, the migration `create_media_tables`, the three controllers and their requests, the page `resources/js/pages/media-items/index.tsx`, `resources/js/components/media-item/*`, `resources/js/components/media-folder/manager-dialog.tsx`, `resources/js/types/media-item.ts`, and the tests of each
- under `scaffold/`, written once and the project's from then on: `app/Infra/Media/MediaServiceProvider.php`, `config/media.php`, `app/Policies/MediaItemPolicy.php` and `MediaFolderPolicy.php` with their tests, and `tests/Helpers/MediaLibrary.php`
- `lang/en.json` of the feature: every key a project's `lang/*.json` lacks is added in English on each install, for the project to translate

The steps a project does by hand once are listed by `kit:install --with=media`, from the feature's `feature.json`: require `intervention/image`, register the provider, route the library, `storage:link`, migrate, put the page in the menu through its manifest, build. *Check `binding`, `routes`.*

**Do**
- Bind every port of the library in `MediaServiceProvider`, the project's own file. Bind an adapter of your own there, never by editing a kit file. *Check `binding`.*
- Route the library in the `auth` group, not `verified`, so the policies alone decide who gets in, as the audit log does. *Check `routes`.*
- Narrow `MediaItemPolicy` and `MediaFolderPolicy` to the roles that keep the library, and change `mediaLibraryManager()` and `mediaLibraryOutsider()` with them: the kit's tests sign in through those two, so they never name a role. *Review only.*
- Translate every key the feature added to a locale file that is not English. *Check `words`.*

**Don't**
- Edit a kit file of the feature. A change the project truly needs is recorded by its owner in `rule-overrides.json` (stack.md). *Check `kit-files`.*

**Why:** every project that installs the library runs the same code, so a fix in the kit reaches all of them with `kit:install`. What differs between projects (who keeps the library, how much it may hold, where the files go) lives in the files the project owns.

## The ports

```php
interface MediaStorage
{
    public function store(UploadedFile $image): MediaFile;
    public function delete(MediaFile $file): void;
    public function url(string $disk, string $path): string;
}

interface MediaQuota
{
    /** @throws MediaQuotaExceededException */
    public function assertRoomFor(int $addedBytes): void;
    public function usage(): MediaQuotaUsage;   // usedBytes, ?limitBytes
}
```

The kit binds `InterventionMediaStorage` (Intervention Image with GD, stack.md) and `UnlimitedMediaQuota`, which sets no limit and sums what the library holds.

**Do**
- Give the library a limit by binding a `MediaQuota` of your own in `MediaServiceProvider`, in `App\Infra`, such as one that reads the subscription's plan. Throw `MediaQuotaExceededException::exceeded($used, $added, $limit)`: the controller already answers it with a toast, or a 409 `media_quota_exceeded` for the picker. *Review only.*
- Count what the quota allows in the bytes `MediaFile::totalBytes()` gives, the original and its thumbnail together, so the page and the refusal agree. *Review only.*
- Pick the disk and the sizes in `config/media.php`. Each image keeps the disk it was written to, so a switch touches new images only. *Review only.*

**Don't**
- Keep private documents in the library. Its disk is public, and every image has a url anyone holding it can load. *Review only.*

## Using an image from another aggregate

An aggregate that shows a library image, such as a profile with a photo, keeps the image's id and tells the library where it is used, so the image cannot be deleted under it.

```php
// app/Application/Profile/ProfileMediaUsage.php
final class ProfileMediaUsage
{
    public const string USABLE_TYPE = 'profile';

    public static function of(string $profileId, string $displayName): MediaUsage
    {
        return MediaUsage::from(self::USABLE_TYPE, $profileId, $displayName);
    }
}

// in UpdateProfileHandler, inside its DB::transaction()
if ($replaced !== null && $replaced !== $profile->photoMediaItemId()) {
    $this->media->detachUsage($replaced, ProfileMediaUsage::of($profile->id(), $profile->displayName()));
}
if ($profile->photoMediaItemId() !== null) {
    $this->media->attachUsage($this->ids->next(), $profile->photoMediaItemId(), ProfileMediaUsage::of($profile->id(), $profile->displayName()));
}
```

**Do**
- Store the image as a nullable `foreignUuid('{x}_media_item_id')->constrained('media_items')->nullOnDelete()` on the aggregate's table, and keep the id on the entity. *Review only.*
- Check the id names an image (`MediaItemRepository::getById()`) before the handler saves it, so a form cannot post one that is gone. *Review only.*
- Attach the usage, and detach the one it replaced, in the same transaction as the aggregate's save. Attaching again only takes the new label, so a rename keeps the label current. *Review only.*
- Name the usable type once, in a `{Aggregate}MediaUsage` class beside the handlers that use it: a snake_case key, never a class name (`MediaUsage` refuses one). *Review only.*
- Give the type a label in every `lang/*.json` as `media-items.usable_types.{type}`: the preview names where an image is used with it. *Review only.*
- Put the field in the form with `MediaPickerField` (`resources/js/components/media-item/picker-field.tsx`). It posts the id through a hidden input and opens the picker, which can upload too. Send the picked image as a `MediaItemRow`, built on the server with `App\Infra\Media\MediaItemRows`, so the field shows its thumbnail. *Review only.*

**Don't**
- Delete the usage rows any other way, or write to `media_usages` outside `attachUsage()` and `detachUsage()`. *Review only.*
- Copy an image's file or url into the aggregate's own table. The url depends on the disk, which may change. *Review only.*

**Why:** the library is one aggregate and the profile another, so neither writes the other's rows (repositories.md). The usage is the library's record of where an image is used, written by the aggregate that uses it, which is the only one that knows when that changes.

## Overrides

Use `rule-overrides.json` with `"rule": "media"`, exactly as stack.md describes. The `subject` is the file's path from the project root for a `kit-files` check, the port's class for `binding`, the controller's class for `routes`, and the locale file for `words`. Only the user may add an entry.
