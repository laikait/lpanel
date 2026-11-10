<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Modules\Agent\AgentKey;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Agent\Transport;

/**
 * An agent for tests that checks what the real one checks about a request's
 * signature, and answers from a script: op => result array, or a Throwable.
 */
final class FakeAgent implements Transport
{
    public readonly AgentKey $key;

    /** @var list<array<string, mixed>> the decoded requests, in order */
    public array $requests = [];

    /** @param array<string, array<string, mixed>|\Throwable|\Closure(array<string, mixed>, string): array<string, mixed>> $answers */
    public function __construct(public array $answers = [])
    {
        $this->key = AgentKey::fromSeed(\str_repeat("\x07", \SODIUM_CRYPTO_SIGN_SEEDBYTES));
    }

    public function exchange(string $line, float $timeout): string
    {
        $request = \json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        \assert(\is_array($request));
        $this->requests[] = $request;

        // The args as they were written on the line, which is what was signed.
        \preg_match('/,"args":(.*),"sig":/s', $line, $m);
        $signed = Protocol::signedBytes($request['id'], $request['op'], $request['ts'], $request['nonce'], $m[1] ?? '');
        $public = \base64_decode($this->key->publicKey(), true);
        $signature = \base64_decode((string) $request['sig'], true);
        $valid = \is_string($public) && $public !== '' && \is_string($signature) && $signature !== ''
            && \strlen($signature) === \SODIUM_CRYPTO_SIGN_BYTES
            && \sodium_crypto_sign_verify_detached($signature, $signed, $public);

        if (!$valid) {
            return \json_encode(['v' => 1, 'id' => $request['id'], 'status' => 'refused', 'error' => ['code' => 'bad_signature', 'message' => 'not signed by the panel']], \JSON_THROW_ON_ERROR);
        }

        $answer = $this->answers[$request['op']] ?? null;

        if ($answer instanceof \Throwable) {
            throw $answer;
        }

        // A closure answers like the agent: a whole response ('status' and
        // 'result' or 'error'), given the args and the request id.
        if ($answer instanceof \Closure) {
            $args = \is_array($request['args']) ? $request['args'] : [];

            return \json_encode(['v' => 1, 'id' => $request['id']] + $answer($args, (string) $request['id']), \JSON_THROW_ON_ERROR);
        }

        if ($answer === null) {
            return \json_encode(['v' => 1, 'id' => $request['id'], 'status' => 'refused', 'error' => ['code' => 'unknown_op', 'message' => 'no such operation']], \JSON_THROW_ON_ERROR);
        }

        return \json_encode(['v' => 1, 'id' => $request['id'], 'status' => 'done', 'result' => $answer], \JSON_THROW_ON_ERROR);
    }

    public static function unavailable(): AgentUnavailable
    {
        return new AgentUnavailable('The agent is not answering on /run/lpa/lpa.sock (No such file or directory).');
    }
}
