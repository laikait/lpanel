<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP\Protocol;

use App\Engine\MCP\McpError;
use App\Engine\MCP\McpErrorCode;
use App\Engine\MCP\Protocol\ProtocolException;
use App\Engine\MCP\Protocol\ProtocolVersion;
use App\Engine\MCP\Protocol\Response;
use App\Tests\Support\TestCase;

final class ResponseTest extends TestCase
{
    public function test_a_result_carries_the_requests_id(): void
    {
        self::assertSame(
            '{"jsonrpc":"2.0","id":"abc","result":{"tools":[]}}',
            Response::result('abc', ['tools' => []])->toJson(),
        );
    }

    public function test_an_empty_result_is_an_object_not_a_list(): void
    {
        self::assertSame('{"jsonrpc":"2.0","id":1,"result":{}}', Response::result(1, [])->toJson());
    }

    public function test_text_is_written_as_it_is(): void
    {
        self::assertSame(
            '{"jsonrpc":"2.0","id":2,"result":{"text":"ঢাকা/path"}}',
            Response::result(2, ['text' => 'ঢাকা/path'])->toJson(),
        );
    }

    public function test_an_error_to_an_unreadable_message_has_a_null_id(): void
    {
        $response = Response::error(null, ProtocolException::parseError()->error());

        self::assertTrue($response->isError());
        self::assertSame(
            '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error: the message is not valid JSON."}}',
            $response->toJson(),
        );
    }

    /** A result that cannot be encoded becomes an internal error, never broken output. */
    public function test_a_result_that_cannot_be_encoded_is_an_internal_error(): void
    {
        self::assertSame(
            '{"jsonrpc":"2.0","id":5,"error":{"code":-32603,"message":"Internal error"}}',
            Response::result(5, ['text' => "\xFF\xFE"])->toJson(),
        );
    }

    public function test_method_not_found_names_the_method_in_its_data(): void
    {
        $error = ProtocolException::methodNotFound('tools/shell', 9);

        self::assertSame(9, $error->requestId);
        self::assertSame(['code' => McpErrorCode::MethodNotFound->value, 'message' => 'Method not found.', 'data' => ['method' => 'tools/shell']], $error->error()->toArray());
        self::assertInstanceOf(McpError::class, $error->error());
    }

    public function test_the_version_the_client_asks_for_is_used_when_supported(): void
    {
        self::assertSame('2025-03-26', ProtocolVersion::negotiate('2025-03-26'));
        self::assertSame(ProtocolVersion::LATEST, ProtocolVersion::negotiate('1999-01-01'));
        self::assertSame(ProtocolVersion::LATEST, ProtocolVersion::negotiate(null));
        self::assertSame(ProtocolVersion::LATEST, ProtocolVersion::negotiate(['2025-06-18']));
        self::assertTrue(ProtocolVersion::isSupported(ProtocolVersion::LATEST));
    }
}
