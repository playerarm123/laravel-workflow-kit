<?php

namespace Playerarm123\LaravelWorkflowKit\Console\Commands;

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Foundation\Console\PolicyMakeCommand;
use Illuminate\Support\Str;
use Override;
use Playerarm123\LaravelWorkflowKit\Console\Commands\Concerns\WritesGeneratedFiles;
use Playerarm123\LaravelWorkflowKit\WorkflowKit;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Laravel's `make:policy`, held to authorization.md: a policy belongs to one model, so the
 * model is read from the name when `--model` is left out, and its test is written beside the
 * other policy tests, every case a todo until it is filled in.
 *
 * Attaching the policy is left to a human — the reminder names the exact attribute, and
 * AuthorizationTest's `policies` check fails until it is there.
 */
#[AsCommand(name: 'make:policy')]
class MakePolicyCommand extends PolicyMakeCommand
{
    use WritesGeneratedFiles;

    protected $description = 'Create a policy for one model, and its test';

    #[Override]
    public function handle(): ?bool
    {
        $this->warnings = [];
        $this->input->setOption('model', $this->option('model') ?: $this->modelName());

        if (parent::handle() === false) {
            return false;
        }

        $policy = $this->qualifyClass($this->getNameInput());
        $model = $this->qualifyModel((string) $this->option('model'));

        $this->writeFromStub(
            WorkflowKit::stubPath('policy-test.stub'),
            base_path('tests/Feature/Policies/'.str_replace('\\', '/', Str::after($policy, $this->getDefaultNamespace(trim($this->rootNamespace(), '\\')).'\\')).'Test.php'),
            'Test',
            [
                '{{ class }}' => class_basename($policy),
                '{{ model }}' => class_basename($model),
                '{{ modelVariable }}' => Str::camel(class_basename($model)),
            ],
        );

        $this->remindToAttach($policy, $model);

        foreach ($this->warnings as $warning) {
            $this->components->warn($warning);
        }

        return null;
    }

    /**
     * The kit's stub, with the five abilities authorization.md expects, always: `handle()` reads
     * the model from the name, so there is never a policy without one.
     */
    #[Override]
    protected function getStub()
    {
        return WorkflowKit::stubPath('policy.stub');
    }

    /**
     * The policy is named after its model, whichever of the two the caller typed.
     */
    #[Override]
    protected function getNameInput()
    {
        return Str::finish(trim((string) $this->argument('name')), 'Policy');
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function render(string $stub, array $replacements): string
    {
        return str_replace(array_keys($replacements), array_values($replacements), $this->files->get($stub));
    }

    private function modelName(): string
    {
        return Str::beforeLast(class_basename(str_replace('/', '\\', $this->getNameInput())), 'Policy');
    }

    private function remindToAttach(string $policy, string $model): void
    {
        if (! class_exists($model)) {
            $this->warnings[] = sprintf('Model [%s] does not exist — create it, then attach the policy with #[UsePolicy(%s::class)].', $model, class_basename($policy));

            return;
        }

        $attached = array_filter(
            (new ReflectionClass($model))->getAttributes(UsePolicy::class),
            fn ($attribute): bool => ($attribute->getArguments()[0] ?? null) === $policy,
        );

        if ($attached === []) {
            $this->warnings[] = sprintf('Attach the policy: add #[UsePolicy(\\%s::class)] to %s.', $policy, $model);
        }
    }
}
