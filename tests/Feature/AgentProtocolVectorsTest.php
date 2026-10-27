<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Modules\Servers\Agent\Protocol;
use PHPUnit\Framework\TestCase;

/**
 * The PHP side signs and verifies exactly the bytes the Go agent does.
 *
 * tests/Fixtures/lpa/vectors.json is a copy of the lpa repository's
 * internal/protocol/testdata/vectors.json, generated there and never edited
 * by hand. When the agent's vectors change, copy the file again.
 */
final class AgentProtocolVectorsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function vectors(): array
    {
        $json = \file_get_contents(\dirname(__DIR__) . '/Fixtures/lpa/vectors.json');
        self::assertIsString($json);
        $vectors = \json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        self::assertIsArray($vectors);

        /** @var array<string, mixed> $vectors */
        return $vectors;
    }

    /** @return array{0: non-empty-string, 1: string} secret key and public key from a hex seed */
    private static function keypair(string $seedHex): array
    {
        $seed = \hex2bin($seedHex);

        if ($seed === false || $seed === '') {
            self::fail('The vectors hold no seed.');
        }

        $pair = \sodium_crypto_sign_seed_keypair($seed);
        return [\sodium_crypto_sign_secretkey($pair), \sodium_crypto_sign_publickey($pair)];
    }

    public function test_keys_and_key_ids_match(): void
    {
        $v = self::vectors();
        [, $agentPublic] = self::keypair((string) $v['agent_seed_hex']);
        [, $panelPublic] = self::keypair((string) $v['panel_seed_hex']);

        self::assertSame($v['agent_public_key'], Protocol::b64($agentPublic));
        self::assertSame($v['agent_kid'], Protocol::keyId($agentPublic));
        self::assertSame($v['panel_public_key'], Protocol::b64($panelPublic));
        self::assertSame($v['panel_kid'], Protocol::keyId($panelPublic));
        self::assertSame($v['sha256_of_empty_body'], Protocol::sha256(''));
    }

    public function test_the_request_signature_is_the_agents(): void
    {
        $v = self::vectors();
        /** @var array<string, string|int> $r */
        $r = $v['request'];
        [$agentSecret, $agentPublic] = self::keypair((string) $v['agent_seed_hex']);

        self::assertSame($r['content_sha256'], Protocol::sha256((string) $r['body']));
        $string = Protocol::signingString(
            (string) $r['method'],
            (string) $r['path'],
            (string) $r['agent_id'],
            (string) $v['agent_kid'],
            (string) $r['request_id'],
            (int) $r['issued_at'],
            (int) $r['expires_at'],
            (string) $r['content_sha256'],
        );

        self::assertSame($r['signing_string'], $string);
        self::assertTrue(Protocol::verify($agentPublic, $string, (string) $r['signature']));
        // Ed25519 is deterministic: signing here gives the agent's very bytes.
        self::assertSame($r['signature'], Protocol::b64(\sodium_crypto_sign_detached($string, $agentSecret)));
        self::assertFalse(Protocol::verify($agentPublic, $string . ' ', (string) $r['signature']));
    }

    public function test_the_operation_signature_is_what_the_agent_verifies(): void
    {
        $v = self::vectors();
        /** @var array{operation_json: string, signed: array{operation: string, kid: string, signature: string}} $o */
        $o = $v['operation'];
        [$panelSecret] = self::keypair((string) $v['panel_seed_hex']);

        self::assertSame($o['signed'], Protocol::signOperation($panelSecret, $o['operation_json']));
    }

    public function test_the_rotation_proof_is_the_agents(): void
    {
        $v = self::vectors();
        /** @var array{agent_id: string, new_kid: string, new_tls_cert_sha256: string, proof_string: string, proof: string} $r */
        $r = $v['rotation'];
        [$secret, $public] = self::keypair((string) $v['agent_seed_hex']);
        $string = Protocol::rotationProofString($r['agent_id'], $r['new_kid'], $r['new_tls_cert_sha256']);

        self::assertSame($r['new_kid'], Protocol::keyId($public));
        self::assertSame($r['proof_string'], $string);
        self::assertTrue(Protocol::verify($public, $string, $r['proof']));
        self::assertSame($r['proof'], Protocol::b64(\sodium_crypto_sign_detached($string, $secret)));
    }

    public function test_base64url_is_strict(): void
    {
        self::assertSame("\xfb\xff", Protocol::unb64('-_8'));
        self::assertNull(Protocol::unb64('-_8='), 'no padding');
        self::assertNull(Protocol::unb64('+/8'), 'not the standard alphabet');
        self::assertNull(Protocol::unb64(''));
        self::assertMatchesRegularExpression(Protocol::UUID, Protocol::uuid());
    }
}
