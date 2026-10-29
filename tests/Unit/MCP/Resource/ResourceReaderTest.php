<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP\Resource;

use App\Engine\Container\Container;
use App\Engine\MCP\McpCollector;
use App\Engine\MCP\McpContractException;
use App\Engine\MCP\McpErrorCode;
use App\Engine\MCP\McpRegistry;
use App\Engine\MCP\Resource\ResourceException;
use App\Engine\MCP\Resource\ResourceReader;
use App\Tests\Fixtures\MCP\Authorizers;
use App\Tests\Fixtures\MCP\Contexts;
use App\Tests\Fixtures\MCP\CustomerResource;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ResourceReaderTest extends TestCase
{
    /** @var list<\Throwable> */
    private array $reported = [];

    private ResourceReader $reader;

    protected function setUp(): void
    {
        CustomerResource::$reads = [];

        $registry = new McpRegistry();
        (new McpCollector($registry, 'Customer'))
            ->resource('customer://{id}', CustomerResource::class, 'One customer.')
            ->resource('customer://recent', CustomerResource::class, 'The latest customers.')
            ->resource('order://{customer}/orders/{order}', CustomerResource::class);

        $this->reader = new ResourceReader($registry, new Container(), Authorizers::open(), function (\Throwable $e): void {
            $this->reported[] = $e;
        });
    }

    private function refused(mixed $uri, McpErrorCode $code): ResourceException
    {
        try {
            $this->reader->read($uri, Contexts::make());
            self::fail('the read was answered');
        } catch (ResourceException $e) {
            self::assertSame($code, $e->error()->code);

            return $e;
        }
    }

    public function test_concrete_resources_and_templates_are_listed_apart(): void
    {
        self::assertSame([['uri' => 'customer://recent', 'name' => 'customer://recent', 'description' => 'The latest customers.']], $this->reader->resources(Contexts::make()));
        self::assertSame(['customer://{id}', 'order://{customer}/orders/{order}'], \array_column($this->reader->templates(Contexts::make()), 'uriTemplate'));
    }

    public function test_a_template_read_passes_its_parameters(): void
    {
        $contents = $this->reader->read('customer://42', Contexts::make());

        self::assertSame(['uri' => 'customer://42', 'mimeType' => 'application/json', 'text' => '{"id":42,"name":"Ada"}'], $contents->toArray());
        self::assertSame([['customer://42', ['id' => '42']]], CustomerResource::$reads);
    }

    public function test_an_exact_uri_wins_over_a_template_that_would_also_match(): void
    {
        $this->reader->read('customer://recent', Contexts::make());

        self::assertSame([['customer://recent', []]], CustomerResource::$reads);
    }

    public function test_several_placeholders_are_each_one_segment(): void
    {
        $this->reader->read('order://7/orders/99', Contexts::make());

        self::assertSame([['order://7/orders/99', ['customer' => '7', 'order' => '99']]], CustomerResource::$reads);
    }

    public function test_placeholders_are_percent_decoded(): void
    {
        $this->reader->read('customer://ada%20lovelace', Contexts::make());

        self::assertSame(['id' => 'ada lovelace'], CustomerResource::$reads[0][1]);
    }

    public function test_binary_contents_are_base64(): void
    {
        self::assertSame(['uri' => 'customer://logo', 'mimeType' => 'image/png', 'blob' => \base64_encode("\x89PNG")], $this->reader->read('customer://logo', Contexts::make())->toArray());
    }

    /** @return iterable<string, array{string}> */
    public static function unmatched(): iterable
    {
        yield 'another scheme' => ['invoice://42'];
        yield 'an extra segment, where a placeholder is one' => ['customer://42/../../etc/passwd'];
        yield 'an encoded slash to smuggle a segment in' => ['customer://..%2F..%2Fetc%2Fpasswd'];
        yield 'a query' => ['customer://42?admin=1'];
        yield 'a missing segment' => ['order://7/orders/'];
    }

    #[DataProvider('unmatched')]
    public function test_a_uri_no_template_matches_is_not_found_and_reaches_no_handler(string $uri): void
    {
        $e = $this->refused($uri, McpErrorCode::ResourceNotFound);

        self::assertSame(['uri' => $uri], $e->error()->data);
        self::assertSame([], CustomerResource::$reads);
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidUris(): iterable
    {
        yield 'not a string' => [['customer://1']];
        yield 'relative' => ['customer/42'];
        yield 'nothing after the scheme' => ['customer://'];
        yield 'a file path' => ['/etc/passwd'];
        yield 'whitespace' => ['customer://4 2'];
        yield 'a control character' => ["customer://42\n"];
        yield 'too long' => ['customer://' . \str_repeat('9', ResourceReader::MAX_URI)];
    }

    #[DataProvider('invalidUris')]
    public function test_something_that_is_not_an_absolute_uri_is_invalid_params(mixed $uri): void
    {
        $this->refused($uri, McpErrorCode::InvalidParams);
    }

    public function test_the_handlers_own_not_found_looks_the_same_as_no_match(): void
    {
        self::assertSame(
            $this->refused('customer://missing', McpErrorCode::ResourceNotFound)->error()->message,
            $this->refused('invoice://1', McpErrorCode::ResourceNotFound)->error()->message,
        );
    }

    public function test_an_unexpected_failure_is_reported_and_the_client_learns_nothing(): void
    {
        $e = $this->refused('customer://explode', McpErrorCode::InternalError);

        self::assertSame('Internal error', $e->error()->message);
        self::assertCount(1, $this->reported);
        self::assertStringContainsString('S3cret', $this->reported[0]->getMessage());
    }

    public function test_a_handler_that_is_not_a_resource_is_a_contract_error(): void
    {
        $registry = new McpRegistry();
        (new McpCollector($registry))->resource('thing://{id}', \ArrayObject::class);

        $this->expectException(McpContractException::class);

        (new ResourceReader($registry, new Container(), Authorizers::open()))->read('thing://1', Contexts::make());
    }
}
