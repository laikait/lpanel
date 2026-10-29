<?php

declare(strict_types=1);

namespace App\Tests\Unit\MCP;

use App\Engine\Error\FrameworkException;
use App\Engine\MCP\McpContractException;
use App\Engine\MCP\McpError;
use App\Engine\MCP\McpErrorCode;
use App\Engine\MCP\McpException;
use App\Tests\Support\TestCase;

final class McpErrorTest extends TestCase
{
    public function test_the_codes_are_json_rpcs(): void
    {
        self::assertSame(-32700, McpErrorCode::ParseError->value);
        self::assertSame(-32600, McpErrorCode::InvalidRequest->value);
        self::assertSame(-32601, McpErrorCode::MethodNotFound->value);
        self::assertSame(-32602, McpErrorCode::InvalidParams->value);
        self::assertSame(-32603, McpErrorCode::InternalError->value);
        self::assertSame(-32002, McpErrorCode::ResourceNotFound->value);
    }

    public function test_an_error_becomes_the_json_rpc_error_object(): void
    {
        self::assertSame(
            ['code' => -32602, 'message' => 'Invalid params', 'data' => ['field' => 'id', 'reason' => 'required']],
            (new McpError(McpErrorCode::InvalidParams, 'Invalid params', ['field' => 'id', 'reason' => 'required']))->toArray(),
        );

        self::assertSame(['code' => -32603, 'message' => 'Internal error'], McpError::internal()->toArray());
    }

    public function test_an_error_without_a_message_is_refused(): void
    {
        $this->expectException(McpContractException::class);

        new McpError(McpErrorCode::InternalError, '  ');
    }

    public function test_error_data_cannot_carry_an_object_however_deep(): void
    {
        try {
            new McpError(McpErrorCode::InvalidParams, 'Invalid params', ['errors' => [['value' => new \ArrayObject()]]]);
            self::fail('an object was accepted as error data');
        } catch (McpContractException $e) {
            self::assertStringContainsString('data.errors.0.value holds ArrayObject', $e->getMessage());
        }
    }

    public function test_every_mcp_exception_knows_what_the_client_is_told(): void
    {
        $e = McpContractException::emptyErrorMessage();

        self::assertInstanceOf(McpException::class, $e);
        self::assertInstanceOf(FrameworkException::class, $e);
        self::assertSame(McpError::internal()->toArray(), $e->error()->toArray(), 'a contract mistake is internal to the client');
        self::assertTrue((new \ReflectionClass(McpException::class))->isAbstract());
    }
}
