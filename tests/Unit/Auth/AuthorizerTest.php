<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Engine\Auth\AccessCollector;
use App\Engine\Auth\AccessRegistry;
use App\Engine\Auth\AuthException;
use App\Engine\Auth\Authorizer;
use App\Engine\Auth\Capability;
use App\Engine\Auth\Identity;
use App\Engine\Filter\FilterEngine;
use App\Engine\Http\HttpException;
use App\Tests\Support\TestCase;

/**
 * The decision, and the one rule that makes this model different from a Gate.
 *
 * Half of this file is about the asymmetry: a filter may turn an allow into a
 * refusal and may not turn a refusal into an allow. That is what keeps the
 * grant side declarative -- "who can do this" stays answerable by reading
 * roles, and no closure anywhere can quietly add somebody.
 */
final class AuthorizerTest extends TestCase
{
    private AccessRegistry $access;

    private FilterEngine $filters;

    protected function setUp(): void
    {
        $this->access = new AccessRegistry();
        $this->filters = new FilterEngine();

        (new AccessCollector($this->access, 'Billing'))
            ->capability('invoice.void')
            ->capability('invoice.issue')
            ->capability('invoice.read')
            ->role('clerk', ['invoice.issue', 'invoice.read'])
            ->role('manager', ['invoice.*'])
            ->role('root', ['*']);
    }

    private function authorizer(bool $withFilters = true): Authorizer
    {
        return new Authorizer($this->access, $withFilters ? $this->filters : null);
    }

    private function identity(string ...$roles): Identity
    {
        return new Identity('7', 'ada', \array_values($roles));
    }

    // ---- the base decision -------------------------------------------------

    public function test_a_role_that_grants_it_allows_it(): void
    {
        self::assertTrue($this->authorizer()->allows($this->identity('clerk'), 'invoice.issue'));
    }

    public function test_a_role_that_does_not_grant_it_refuses(): void
    {
        self::assertFalse($this->authorizer()->allows($this->identity('clerk'), 'invoice.void'));
        self::assertTrue($this->authorizer()->denies($this->identity('clerk'), 'invoice.void'));
    }

    public function test_a_wildcard_role_covers_the_family(): void
    {
        self::assertTrue($this->authorizer()->allows($this->identity('manager'), 'invoice.void'));
    }

    public function test_everything_means_everything(): void
    {
        self::assertTrue($this->authorizer()->allows($this->identity('root'), 'invoice.void'));
    }

    /** Deny by default: no roles, no capabilities. */
    public function test_a_guest_is_refused(): void
    {
        self::assertFalse($this->authorizer()->allows(Identity::guest(), 'invoice.read'));
    }

    /**
     * A capability nobody declared is a mistake, not a refusal.
     *
     * Answering "no" would be safe and would look exactly like a routing fault
     * or a broken login to whoever has to debug it -- the one thing a silent
     * "no" never says is that the capability does not exist.
     */
    public function test_an_undeclared_capability_is_a_mistake_and_says_so(): void
    {
        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Nothing declares the capability "invoice.viod"');

        $this->authorizer()->allows($this->identity('root'), 'invoice.viod');
    }

    // ---- the filter may narrow ---------------------------------------------

    public function test_a_filter_can_refuse_what_the_roles_allowed(): void
    {
        $this->filters->add(
            Authorizer::DECISION_FILTER,
            static fn(bool $allowed, Capability $capability, Identity $identity, mixed $subject): bool
                => $subject === 'somebody else\'s invoice' ? false : $allowed,
            10,
            'Billing',
        );

        $authorizer = $this->authorizer();
        $identity = $this->identity('manager');

        self::assertTrue($authorizer->allows($identity, 'invoice.void', 'their own invoice'));
        self::assertFalse($authorizer->allows($identity, 'invoice.void', 'somebody else\'s invoice'));
    }

    /**
     * The rule that makes this not a Gate.
     *
     * A listener returning true where the roles said no changes nothing. If it
     * could, then "who may void an invoice" would only be answerable by reading
     * every listener, which is the property that makes an access model
     * unauditable.
     */
    public function test_a_filter_cannot_grant_what_the_roles_refused(): void
    {
        $called = false;

        $this->filters->add(
            Authorizer::DECISION_FILTER,
            static function (bool $allowed) use (&$called): bool {
                $called = true;

                return true;
            },
            10,
            'Billing',
        );

        self::assertFalse($this->authorizer()->allows($this->identity('clerk'), 'invoice.void'));
        self::assertFalse($called, 'the chain is not even run for a decision it could not change');
    }

    /** Fail closed: anything that is not an explicit true is a refusal. */
    public function test_a_filter_that_answers_with_something_odd_refuses(): void
    {
        $this->filters->add(
            Authorizer::DECISION_FILTER,
            static fn(): string => 'yes, obviously',
            10,
            'Billing',
        );

        self::assertFalse($this->authorizer()->allows($this->identity('root'), 'invoice.void'));
    }

    public function test_without_a_filter_engine_the_roles_are_the_whole_answer(): void
    {
        self::assertTrue($this->authorizer(withFilters: false)->allows($this->identity('root'), 'invoice.void'));
    }

    // ---- refusing a request ------------------------------------------------

    public function test_authorize_is_silent_when_allowed(): void
    {
        $this->expectNotToPerformAssertions();

        $this->authorizer()->authorize($this->identity('root'), 'invoice.void');
    }

    /** 401 and 403 mean different things, and the difference is who is asking. */
    public function test_a_guest_gets_401_and_a_known_account_gets_403(): void
    {
        $authorizer = $this->authorizer();

        try {
            $authorizer->authorize(Identity::guest(), 'invoice.void');
            self::fail('a guest should have been refused');
        } catch (HttpException $e) {
            self::assertSame(401, $e->status());
            self::assertSame('Bearer', $e->headers()['WWW-Authenticate'] ?? null);
        }

        try {
            $authorizer->authorize($this->identity('clerk'), 'invoice.void');
            self::fail('a clerk should have been refused');
        } catch (HttpException $e) {
            self::assertSame(403, $e->status());
            self::assertStringContainsString('invoice.void', $e->getMessage());
        }
    }

    // ---- what a screen needs -----------------------------------------------

    public function test_the_capability_list_expands_wildcards(): void
    {
        $held = $this->authorizer()->capabilitiesFor($this->identity('manager'));

        \sort($held);

        self::assertSame(['invoice.issue', 'invoice.read', 'invoice.void'], $held);
    }

    public function test_the_capability_list_of_a_guest_is_empty(): void
    {
        self::assertSame([], $this->authorizer()->capabilitiesFor(Identity::guest()));
    }

    public function test_grants_are_what_the_roles_say_rather_than_what_exists(): void
    {
        self::assertSame(['invoice.*'], $this->authorizer()->grantsFor($this->identity('manager')));
    }
}
