<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP\Prompt;

use App\Engine\Container\Container;
use App\Engine\MCP\McpCollector;
use App\Engine\MCP\McpContractException;
use App\Engine\MCP\McpErrorCode;
use App\Engine\MCP\McpRegistry;
use App\Engine\MCP\Prompt\PromptArgument;
use App\Engine\MCP\Prompt\PromptException;
use App\Engine\MCP\Prompt\PromptMessage;
use App\Engine\MCP\Prompt\PromptProvider;
use App\Engine\MCP\Prompt\PromptResult;
use App\Tests\Fixtures\MCP\Authorizers;
use App\Tests\Fixtures\MCP\Contexts;
use App\Tests\Fixtures\MCP\SupportPrompt;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PromptProviderTest extends TestCase
{
    /** @var list<\Throwable> */
    private array $reported = [];

    private PromptProvider $prompts;

    protected function setUp(): void
    {
        $registry = new McpRegistry();
        (new McpCollector($registry, 'Customer'))->prompt('customer.support', SupportPrompt::class, 'Start a support conversation.');

        $this->prompts = new PromptProvider($registry, new Container(), Authorizers::open(), function (\Throwable $e): void {
            $this->reported[] = $e;
        });
    }

    public function test_prompts_are_listed_with_their_arguments(): void
    {
        self::assertSame(
            [[
                'name' => 'customer.support',
                'description' => 'Start a support conversation.',
                'arguments' => [
                    ['name' => 'customer', 'description' => 'The customer id.', 'required' => true],
                    ['name' => 'tone', 'description' => 'How formal to be.', 'required' => false],
                ],
            ]],
            $this->prompts->list(Contexts::make()),
        );
    }

    public function test_a_prompt_resolves_to_its_messages(): void
    {
        self::assertSame(
            [
                'description' => 'Support conversation starter',
                'messages' => [
                    ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Help customer 42 with their open invoices.']],
                    ['role' => 'assistant', 'content' => ['type' => 'text', 'text' => 'Tone: formal']],
                ],
            ],
            $this->prompts->get('customer.support', ['customer' => '42', 'tone' => 'formal'], Contexts::make())->toArray(),
        );
    }

    public function test_an_optional_argument_may_be_left_out(): void
    {
        self::assertStringContainsString('neutral', (string) \json_encode($this->prompts->get('customer.support', ['customer' => '42'], Contexts::make())->toArray()));
    }

    public function test_an_unknown_prompt_is_invalid_params(): void
    {
        try {
            $this->prompts->get('customer.delete', [], Contexts::make());
            self::fail('an unknown prompt resolved');
        } catch (PromptException $e) {
            self::assertSame(McpErrorCode::InvalidParams, $e->error()->code);
            self::assertSame(['prompt' => 'customer.delete'], $e->error()->data);
        }
    }

    /** @return iterable<string, array{mixed, string, ?string}> */
    public static function badArguments(): iterable
    {
        yield 'a required argument missing' => [['tone' => 'formal'], 'a required argument is missing', 'customer'];
        yield 'nothing at all' => [null, 'a required argument is missing', 'customer'];
        yield 'an argument the prompt does not declare' => [['customer' => '42', 'role' => 'admin'], 'no such argument', 'role'];
        yield 'a value that is not a string' => [['customer' => 42], 'argument values are strings', 'customer'];
        yield 'a list' => [['42'], 'an object of names to strings', null];
        yield 'a string' => ['42', 'an object of names to strings', null];
    }

    #[DataProvider('badArguments')]
    public function test_arguments_that_do_not_fit_the_declaration_are_refused(mixed $arguments, string $why, ?string $argument): void
    {
        try {
            $this->prompts->get('customer.support', $arguments, Contexts::make());
            self::fail('bad arguments were accepted');
        } catch (PromptException $e) {
            self::assertStringContainsString($why, $e->error()->message);
            self::assertSame($argument, $e->error()->data['argument'] ?? null);
        }
    }

    public function test_an_unexpected_failure_is_reported_and_answered_as_internal(): void
    {
        try {
            $this->prompts->get('customer.support', ['customer' => 'explode'], Contexts::make());
            self::fail('no exception');
        } catch (PromptException $e) {
            self::assertSame(McpErrorCode::InternalError, $e->error()->code);
        }

        self::assertCount(1, $this->reported);
        self::assertStringContainsString('S3cret', $this->reported[0]->getMessage());
    }

    public function test_a_handler_that_is_not_a_prompt_is_a_contract_error(): void
    {
        $registry = new McpRegistry();
        (new McpCollector($registry))->prompt('nope', \ArrayObject::class);

        $this->expectException(McpContractException::class);

        (new PromptProvider($registry, new Container(), Authorizers::open()))->get('nope', [], Contexts::make());
    }

    public function test_declarations_and_results_are_checked_when_made(): void
    {
        foreach ([
            static fn() => new PromptArgument('Customer Id'),
            static fn() => new PromptResult([]),
        ] as $make) {
            try {
                $make();
                self::fail('an invalid prompt value was made');
            } catch (McpContractException) {
            }
        }

        self::assertSame('assistant', PromptMessage::assistant('ok')->role);
    }
}
