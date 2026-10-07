<?php

namespace Playerarm123\LaravelWorkflowKit\Tests\Architecture;

use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tests\PHPStan\DatabaseWriteOutsideInfraRule;

require_once __DIR__.'/Support/rules.php';

/**
 * Proves tests/PHPStan/DatabaseWriteOutsideInfraRule, the enforcement of
 * write-path.md, against fixtures that write in every way it must catch and in
 * the ways it must let through.
 *
 * @extends RuleTestCase<DatabaseWriteOutsideInfraRule>
 */
final class WritePathRuleTest extends RuleTestCase
{
    private const string FIXTURES = 'tests/PHPStan/Fixtures/WritePath';

    /**
     * Larastan boots the Laravel app — and with it the app's error and exception handlers — when
     * PHPStan's container is built. Building it here, before any test runs, keeps that boot from
     * being read as a test that leaked its handlers.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::getContainer();
    }

    protected function getRule(): Rule
    {
        return new DatabaseWriteOutsideInfraRule(
            self::getContainer()->getByType(ReflectionProvider::class),
            ['App\Http\Controllers\WritePathFixtures\ExemptWrites'],
        );
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [ruleProjectPath('vendor/larastan/larastan/extension.neon')];
    }

    public function test_it_reports_every_database_write_outside_the_infrastructure(): void
    {
        $this->analyse([ruleProjectPath(self::FIXTURES.'/EntryPointWrites.php')], [
            [$this->message('deletesAModel', 'App\Models\Customer::delete()'), 13],
            [$this->message('updatesThroughTheQueryBuilder', 'Illuminate\Database\Eloquent\Builder::update()'), 18],
            [$this->message('insertsThroughTheDbFacade', 'Illuminate\Database\Query\Builder::insert()'), 23],
            [$this->message('runsARawStatement', 'DB::statement()'), 28],
            [$this->message('createsStatically', 'App\Models\Customer::create()'), 33],
        ]);
    }

    public function test_it_lets_the_infrastructure_write(): void
    {
        $this->analyse([ruleProjectPath(self::FIXTURES.'/InfraWrites.php')], []);
    }

    public function test_it_lets_an_exempted_class_write(): void
    {
        $this->analyse([ruleProjectPath(self::FIXTURES.'/ExemptWrites.php')], []);
    }

    private function message(string $method, string $call): string
    {
        return sprintf(
            '[write-path:outside-infra] App\Http\Controllers\WritePathFixtures\EntryPointWrites::%s() writes through %s — go through a use-case handler and its repository. See write-path.md in the workflow kit\'s guidelines',
            $method,
            $call,
        );
    }
}
