<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Modules\Agent\AgentKey;
use App\Modules\Agent\AgentReply;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The panel signs exactly what lpa verifies. The vectors are written by the
 * Go tests (go test ./internal/protocol -update), so a change to the signing
 * rule on either side fails here until both agree.
 */
final class AgentProtocolTest extends TestCase
{
    private const VECTORS = __DIR__ . '/../../dist/lpa/internal/protocol/testdata/vectors.json';

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function vectors(): iterable
    {
        $vectors = \json_decode((string) \file_get_contents(self::VECTORS), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($vectors);

        foreach ($vectors as $vector) {
            yield (string) $vector['name'] => [$vector];
        }
    }

    /** @param array<string, mixed> $v */
    #[DataProvider('vectors')]
    public function test_the_panel_signs_exactly_as_the_agent_verifies(array $v): void
    {
        $key = AgentKey::fromSeed((string) \base64_decode((string) $v['seed'], true));
        $bytes = Protocol::signedBytes((string) $v['id'], (string) $v['op'], (int) $v['ts'], (string) $v['nonce'], (string) $v['args']);

        self::assertSame($v['signed_bytes'], $bytes);
        self::assertSame($v['signature'], $key->sign($bytes));
        self::assertSame($v['public_key'], $key->publicKey());
    }

    public function test_the_line_carries_the_args_byte_for_byte(): void
    {
        $args = Protocol::encodeArgs(['path' => '/home/a b', 'name' => 'Ünï']);
        $line = Protocol::line('0123456789abcdef', 'x.y', 1, 'AAAAAAAAAAAAAAAAAAAAAA', $args, 'c2ln');

        self::assertStringEndsWith("\n", $line);
        self::assertStringContainsString(',"args":' . $args . ',', $line);
        self::assertSame('{"path":"/home/a b","name":"Ünï"}', $args);
        self::assertSame('{}', Protocol::encodeArgs([]));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{22}$/', Protocol::newNonce());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', Protocol::newId());
    }

    public function test_a_list_is_not_accepted_as_arguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Protocol::encodeArgs(['a', 'b']);
    }

    public function test_replies_are_read_strictly(): void
    {
        $done = AgentReply::fromLine('{"v":1,"id":"abc","status":"done","result":{"agent":"0.1.0"}}');
        $refused = AgentReply::fromLine('{"v":1,"status":"refused","error":{"code":"bad_signature","message":"no"}}');

        self::assertTrue($done->done());
        self::assertSame('0.1.0', $done->result['agent']);
        self::assertFalse($refused->done());
        self::assertSame('bad_signature', $refused->errorCode);

        $this->expectException(AgentUnavailable::class);
        AgentReply::fromLine('<html>not the agent</html>');
    }

    public function test_a_key_that_is_not_a_key_is_refused(): void
    {
        $this->expectException(AgentUnavailable::class);
        AgentKey::fromBase64('bm90IGEga2V5');
    }
}
