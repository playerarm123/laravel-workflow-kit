<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Structure;

use Illuminate\Support\Str;

/**
 * What a form kept in a dialog is (form-pages.md, "Form dialogs"), in the one place the
 * generators, the reader, the planner and the editor all read it from: which forms a dialog can
 * hold, the controller methods each loads from and posts to, the path it keeps in the manifest
 * and the file make:form-page writes it to.
 */
final class FormDialogs
{
    /**
     * Each form a dialog can hold, `create` before `edit`, with the controller method it loads
     * its defaults from and then the one it posts to.
     */
    public const array METHODS = ['create' => ['create', 'store'], 'edit' => ['edit', 'update']];

    /**
     * The forms a dialog can hold, each named after the controller method that loads it.
     *
     * @return list<string>
     */
    public static function forms(): array
    {
        return array_keys(self::METHODS);
    }

    /**
     * The form a controller method belongs to: create and store follow create, edit and update
     * follow edit. Null for any other method.
     */
    public static function formOf(string $method): ?string
    {
        foreach (self::METHODS as $form => $methods) {
            if (in_array($method, $methods, true)) {
                return $form;
            }
        }

        return null;
    }

    /**
     * Where make:form-page writes a resource's create or edit page, and the path its form keeps
     * in the manifest when it lives in a dialog: `Crate`, `edit` → `crates/edit`.
     */
    public static function pageOf(string $model, string $form): string
    {
        return self::folderOf($model).'/'.$form;
    }

    /**
     * The folder make:form-page writes a model's pages to, and the list page the dialogs open
     * from sits in: `Crate` → `crates`.
     */
    public static function folderOf(string $model): string
    {
        return Str::kebab(Str::pluralStudly($model));
    }

    /**
     * The forms a manifest keeps in a dialog, `create` before `edit`: each page of kind `dialog`
     * at the path its form's page would have.
     *
     * @param  array<string, string>  $pages
     * @return list<string>
     */
    public static function inPages(string $model, array $pages): array
    {
        return array_values(array_filter(
            self::forms(),
            fn (string $form): bool => ($pages[self::pageOf($model, $form)] ?? null) === 'dialog',
        ));
    }

    /**
     * `resources/js/components/{model}/form-dialog.tsx`, where make:form-page writes the dialogs.
     */
    public static function path(string $root, string $model): string
    {
        return $root.'/resources/js/components/'.Str::kebab($model).'/form-dialog.tsx';
    }

    /**
     * The forms the model's `form-dialog.tsx` exports a dialog for, `Create{Model}Dialog` or
     * `Edit{Model}Dialog`, read in one go. Empty when the file is not there.
     *
     * @return list<string>
     */
    public static function exported(string $root, string $model): array
    {
        return self::exportedIn(StructureFiles::text(self::path($root, $model)), $model);
    }

    /**
     * The forms a `form-dialog.tsx`'s text exports a dialog for.
     *
     * @return list<string>
     */
    public static function exportedIn(string $contents, string $model): array
    {
        return array_values(array_filter(
            self::forms(),
            fn (string $form): bool => preg_match('/^export\s+function\s+'.self::dialogOf($model, $form).'\b/m', $contents) === 1,
        ));
    }

    /**
     * The component a form's dialog is: `Crate`, `edit` → `EditCrateDialog`.
     */
    public static function dialogOf(string $model, string $form): string
    {
        return Str::studly($form).$model.'Dialog';
    }

    /**
     * How a controller's source answers a method: `json` for a dialog, `page` for anything else,
     * null when it has no such method. Read from the text rather than the loaded class, because a
     * generator may have changed it since the class was loaded.
     */
    public static function answerOf(string $contents, string $method): ?string
    {
        if (preg_match('/^\s*public function '.$method.'\s*\([^)]*\)(?:\s*:\s*\\\\?([\w\\\\]+))?/m', $contents, $match) !== 1) {
            return null;
        }

        return in_array($match[1] ?? '', ['JsonResponse', 'Illuminate\Http\JsonResponse'], true) ? 'json' : 'page';
    }
}
