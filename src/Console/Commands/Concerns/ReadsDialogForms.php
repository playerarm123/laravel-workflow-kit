<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns;

use Playerarm123\LaravelWorkflowKit\Console\Commands\Structure\FormDialogs;

/**
 * `--dialog[=create,edit]`, which `make:controller` and `make:form-page` share: the forms of a
 * resource that live in a dialog instead of a page of their own (form-pages.md, "Form dialogs").
 */
trait ReadsDialogForms
{
    /**
     * The forms `--dialog` names: none when it is left out, both when it names none. Null, with
     * the error printed, when it names something that is not a form.
     *
     * @return list<string>|null
     */
    protected function dialogFormsNamed(): ?array
    {
        $option = $this->option('dialog');

        if ($option === false || ($option === null && ! $this->input->hasParameterOption('--dialog'))) {
            return [];
        }

        if (! is_string($option) || $option === '') {
            return FormDialogs::forms();
        }

        $named = array_values(array_filter(array_map('trim', explode(',', strtolower($option)))));
        $unknown = array_diff($named, FormDialogs::forms());

        if ($unknown !== []) {
            $this->components->error(sprintf(
                '--dialog names %s, which is not a form. Pick from %s.',
                implode(', ', $unknown),
                implode(', ', FormDialogs::forms()),
            ));

            return null;
        }

        return array_values(array_intersect(FormDialogs::forms(), $named));
    }
}
