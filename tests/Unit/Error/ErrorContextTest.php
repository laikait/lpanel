<?php

declare(strict_types=1);

namespace App\Tests\Unit\Error;

use App\Engine\Error\ErrorContext;
use App\Engine\Http\Request;
use App\Tests\Support\TestCase;

/**
 * Who is reading the error.
 *
 * The specification lists production and development alongside browser, REST
 * and console, but those are a different axis: one decides how much is said,
 * this decides to whom. Keeping them apart is what makes "a framework message
 * is fine on a terminal and not in a response" expressible at all.
 */
final class ErrorContextTest extends TestCase
{
    public function test_a_json_client_is_a_machine(): void
    {
        $request = Request::create('GET', '/customers', ['headers' => ['Accept' => 'application/json']]);

        self::assertSame(ErrorContext::Api, ErrorContext::of($request));
        self::assertTrue(ErrorContext::of($request)->isMachine());
    }

    public function test_a_browser_is_not(): void
    {
        $request = Request::create('GET', '/customers', [
            'headers' => ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8'],
        ]);

        self::assertSame(ErrorContext::Browser, ErrorContext::of($request));
        self::assertFalse(ErrorContext::of($request)->isMachine());
    }

    public function test_a_request_that_says_nothing_is_treated_as_a_browser(): void
    {
        self::assertSame(ErrorContext::Browser, ErrorContext::of(Request::create('GET', '/customers')));
    }

    /**
     * The rule the whole type exists for.
     *
     * An operator already has the source, the configuration and the directory
     * listing; withholding a framework-authored message from them protects
     * nobody. A browser and an API client are not in that position.
     */
    public function test_only_the_console_may_repeat_a_framework_message(): void
    {
        self::assertTrue(ErrorContext::Console->disclosesFrameworkMessages());
        self::assertFalse(ErrorContext::Browser->disclosesFrameworkMessages());
        self::assertFalse(ErrorContext::Api->disclosesFrameworkMessages());
    }

    public function test_the_console_is_not_a_machine_audience(): void
    {
        self::assertFalse(ErrorContext::Console->isMachine(), 'a person reads console output');
    }
}
