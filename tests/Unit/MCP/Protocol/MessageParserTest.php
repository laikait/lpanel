<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP\Protocol;

use App\Engine\MCP\McpErrorCode;
use App\Engine\MCP\Protocol\MessageParser;
use App\Engine\MCP\Protocol\Notification;
use App\Engine\MCP\Protocol\ProtocolException;
use App\Engine\MCP\Protocol\Request;
use App\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class MessageParserTest extends TestCase
{
    private function refused(string $json, McpErrorCode $code, string|int|null $id = null, int $maxBytes = MessageParser::DEFAULT_MAX_BYTES): ProtocolException
    {
        try {
            (new MessageParser($maxBytes))->parse($json);
            self::fail('the message was accepted');
        } catch (ProtocolException $e) {
            self::assertSame($code, $e->error()->code, $e->getMessage());
            self::assertSame($id, $e->requestId);

            return $e;
        }
    }

    // ---- valid messages -----------------------------------------------------------------

    public function test_a_request_with_named_parameters(): void
    {
        $message = (new MessageParser())->parse('{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"customer.get","arguments":{"id":42,"tags":["a"]}}}');

        self::assertInstanceOf(Request::class, $message);
        self::assertSame(7, $message->id);
        self::assertSame('tools/call', $message->method);
        self::assertSame(['name' => 'customer.get', 'arguments' => ['id' => 42, 'tags' => ['a']]], $message->params);
    }

    public function test_a_string_id_is_kept_as_a_string(): void
    {
        $message = (new MessageParser())->parse('{"jsonrpc":"2.0","id":"abc-1","method":"ping"}');

        self::assertInstanceOf(Request::class, $message);
        self::assertSame('abc-1', $message->id);
        self::assertSame([], $message->params);
    }

    public function test_a_message_without_an_id_is_a_notification(): void
    {
        $message = (new MessageParser())->parse('{"jsonrpc":"2.0","method":"notifications/initialized"}');

        self::assertInstanceOf(Notification::class, $message);
        self::assertSame('notifications/initialized', $message->method);
    }

    public function test_an_empty_params_object_is_an_empty_map(): void
    {
        $message = (new MessageParser())->parse('{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}');

        self::assertInstanceOf(Request::class, $message);
        self::assertSame([], $message->params);
    }

    // ---- invalid messages ----------------------------------------------------------------

    public function test_text_that_is_not_json_is_a_parse_error_with_a_null_id(): void
    {
        $this->refused('{"jsonrpc":"2.0",', McpErrorCode::ParseError);
        $this->refused("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"\xFF\"}", McpErrorCode::ParseError);
    }

    /** @return iterable<string, array{string, McpErrorCode, string|int|null}> */
    public static function invalidRequests(): iterable
    {
        yield 'a batch' => ['[{"jsonrpc":"2.0","id":1,"method":"ping"}]', McpErrorCode::InvalidRequest, null];
        yield 'a scalar' => ['"ping"', McpErrorCode::InvalidRequest, null];
        yield 'no version' => ['{"id":1,"method":"ping"}', McpErrorCode::InvalidRequest, 1];
        yield 'the wrong version' => ['{"jsonrpc":"1.0","id":1,"method":"ping"}', McpErrorCode::InvalidRequest, 1];
        yield 'no method, as a client response would be' => ['{"jsonrpc":"2.0","id":1,"result":{}}', McpErrorCode::InvalidRequest, 1];
        yield 'an empty method' => ['{"jsonrpc":"2.0","id":1,"method":""}', McpErrorCode::InvalidRequest, 1];
        yield 'a method that is not a string' => ['{"jsonrpc":"2.0","id":1,"method":5}', McpErrorCode::InvalidRequest, 1];
        yield 'a null id' => ['{"jsonrpc":"2.0","id":null,"method":"ping"}', McpErrorCode::InvalidRequest, null];
        yield 'a float id' => ['{"jsonrpc":"2.0","id":1.5,"method":"ping"}', McpErrorCode::InvalidRequest, null];
        yield 'a boolean id' => ['{"jsonrpc":"2.0","id":true,"method":"ping"}', McpErrorCode::InvalidRequest, null];
        yield 'positional params' => ['{"jsonrpc":"2.0","id":3,"method":"tools/call","params":["customer.get"]}', McpErrorCode::InvalidParams, 3];
        yield 'params that are a string' => ['{"jsonrpc":"2.0","id":"x","method":"ping","params":"all"}', McpErrorCode::InvalidParams, 'x'];
        yield 'a notification with positional params' => ['{"jsonrpc":"2.0","method":"ping","params":[1]}', McpErrorCode::InvalidRequest, null];
    }

    #[DataProvider('invalidRequests')]
    public function test_a_message_that_is_not_a_valid_request_is_refused(string $json, McpErrorCode $code, string|int|null $id): void
    {
        $this->refused($json, $code, $id);
    }

    public function test_an_oversized_message_is_refused_before_it_is_decoded(): void
    {
        $e = $this->refused('{"jsonrpc":"2.0","id":1,"method":"ping","params":{"x":"' . \str_repeat('a', 200) . '"}}', McpErrorCode::InvalidRequest, maxBytes: 100);

        self::assertStringContainsString('at most 100 bytes', $e->getMessage());
    }

    public function test_absurd_nesting_is_refused(): void
    {
        $this->refused('{"jsonrpc":"2.0","id":1,"method":"ping","params":' . \str_repeat('{"a":', 100) . '1' . \str_repeat('}', 100) . '}', McpErrorCode::ParseError);
    }
}
