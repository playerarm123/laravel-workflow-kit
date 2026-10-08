<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\PHPStan;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Only the infrastructure layer writes to the database (write-path.md). Every
 * create, change or delete elsewhere goes through a use-case handler and its repository.
 *
 * The receiver's real type decides, not the method name: `$model->delete()` is a write,
 * `$storage->delete()` is not. Seeders, factories and migrations may write. An exception is
 * an entry in rule-overrides.json (`rule: write-path`, `check: outside-infra`, `subject:` the
 * class), and only the user may add one; write-path.php beside this file hands the list in.
 *
 * @implements Rule<Node\Expr>
 */
final class DatabaseWriteOutsideInfraRule implements Rule
{
    /** Methods that write when called on a model, a query builder or a relation. */
    private const array WRITE_METHODS = [
        'save', 'saveQuietly', 'saveOrFail', 'update', 'updateQuietly', 'updateOrFail', 'updateOrCreate', 'updateOrInsert',
        'delete', 'deleteQuietly', 'deleteOrFail', 'forceDelete', 'forceDeleteQuietly', 'restore', 'restoreQuietly',
        'create', 'createQuietly', 'createMany', 'createManyQuietly', 'forceCreate', 'forceCreateQuietly', 'firstOrCreate',
        'createOrFirst', 'insert', 'insertOrIgnore', 'insertGetId', 'insertUsing', 'upsert', 'increment', 'decrement',
        'incrementEach', 'decrementEach', 'push', 'pushQuietly', 'touch', 'touchQuietly', 'truncate', 'sync', 'syncWithoutDetaching',
        'syncWithPivotValues', 'toggle', 'attach', 'detach', 'updateExistingPivot', 'saveMany', 'saveManyQuietly',
    ];

    /** Static calls on a model class that write. */
    private const array STATIC_WRITE_METHODS = [
        'create', 'createQuietly', 'forceCreate', 'forceCreateQuietly', 'insert', 'insertOrIgnore', 'upsert', 'destroy',
        'updateOrCreate', 'firstOrCreate', 'createOrFirst', 'truncate',
    ];

    /** DB facade calls that write. */
    private const array DB_WRITE_METHODS = ['statement', 'insert', 'update', 'delete', 'unprepared', 'affectingStatement'];

    /** @var list<string> */
    private const array ALLOWED_NAMESPACES = ['App\\Infra\\', 'Database\\Factories\\', 'Database\\Seeders\\'];

    /**
     * The starter kit's own account code beside Fortify. Fortify in vendor already writes the auth
     * columns of users, and these classes are the starter kit's half of that, so the users table
     * keeps that second writer either way.
     *
     * @var list<string>
     */
    private const array STARTER_KIT_NAMESPACES = ['App\\Actions\\Fortify\\', 'App\\Http\\Controllers\\Settings\\'];

    /**
     * @param  list<string>  $exemptClasses  from rule-overrides.json, via tests/PHPStan/write-path.php
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly array $exemptClasses = [],
    ) {}

    public function getNodeType(): string
    {
        return Node\Expr::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $call = match (true) {
            $node instanceof MethodCall, $node instanceof NullsafeMethodCall => $this->writingMethodCall($node, $scope),
            $node instanceof StaticCall => $this->writingStaticCall($node, $scope),
            default => null,
        };

        if ($call === null || $this->mayWrite($scope)) {
            return [];
        }

        $where = $scope->isInClass() ? $scope->getClassReflection()->getName() : basename($scope->getFile());
        $function = $scope->getFunctionName();

        return [
            RuleErrorBuilder::message(sprintf(
                '[write-path:outside-infra] %s%s writes through %s — go through a use-case handler and its repository. See write-path.md in the workflow kit\'s guidelines',
                $where,
                $function === null ? '' : '::'.$function.'()',
                $call,
            ))->identifier('writePath.outsideInfra')->build(),
        ];
    }

    private function writingMethodCall(MethodCall|NullsafeMethodCall $node, Scope $scope): ?string
    {
        if (! $node->name instanceof Identifier || ! in_array($node->name->toString(), self::WRITE_METHODS, true)) {
            return null;
        }

        $type = TypeCombinator::removeNull($scope->getType($node->var));
        $receiver = $this->persistenceTypeOf($type);

        return $receiver === null ? null : sprintf('%s::%s()', $receiver, $node->name->toString());
    }

    private function writingStaticCall(StaticCall $node, Scope $scope): ?string
    {
        if (! $node->class instanceof Name || ! $node->name instanceof Identifier) {
            return null;
        }

        $class = $scope->resolveName($node->class);
        $method = $node->name->toString();

        if ($class === DB::class && in_array($method, self::DB_WRITE_METHODS, true)) {
            return sprintf('DB::%s()', $method);
        }

        if (! in_array($method, self::STATIC_WRITE_METHODS, true) || ! $this->reflectionProvider->hasClass($class)) {
            return null;
        }

        return $this->reflectionProvider->getClass($class)->isSubclassOf(Model::class)
            ? sprintf('%s::%s()', $class, $method)
            : null;
    }

    /**
     * The persistence type the receiver is, or null when it is anything else.
     */
    private function persistenceTypeOf(Type $type): ?string
    {
        foreach ([Model::class, EloquentBuilder::class, QueryBuilder::class, Relation::class] as $persistence) {
            if ((new ObjectType($persistence))->isSuperTypeOf($type)->yes()) {
                return $type->getObjectClassNames()[0] ?? $persistence;
            }
        }

        return null;
    }

    private function mayWrite(Scope $scope): bool
    {
        foreach ([...self::ALLOWED_NAMESPACES, ...self::STARTER_KIT_NAMESPACES] as $namespace) {
            if (str_starts_with($scope->getNamespace().'\\', $namespace)) {
                return true;
            }
        }

        if (str_contains(str_replace('\\', '/', $scope->getFile()), '/database/migrations/')) {
            return true;
        }

        return $scope->isInClass() && in_array($scope->getClassReflection()->getName(), $this->exemptClasses, true);
    }
}
