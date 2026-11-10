<?php

// Raw probes of lpa's socket for tests/e2e/agent.sh. Not part of the panel.
//
//   php probe.php raw '<line>'      send a line, print the answer or NO-ANSWER
//   php probe.php forged            a request signed with a stranger's key
//   php probe.php replay KEYFILE    one signed request sent twice
//   php probe.php stale KEYFILE     a correctly signed request from 5 minutes ago

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Modules\Agent\AgentKey;
use App\Modules\Agent\Protocol;

$socket = getenv('LPANEL_AGENT_SOCKET') ?: '/run/lpa/lpa.sock';

$send = static function (string $line) use ($socket): string {
    $c = @stream_socket_client('unix://' . $socket, $code, $message, 3);
    if ($c === false) {
        return 'NO-CONNECT ' . $message;
    }
    stream_set_timeout($c, 3);
    fwrite($c, $line);
    $answer = stream_get_line($c, 1 << 20, "\n");
    fclose($c);

    return $answer === false || $answer === '' ? 'NO-ANSWER' : $answer;
};

$signed = static function (AgentKey $key, int $ts): string {
    $id = Protocol::newId();
    $nonce = Protocol::newNonce();

    return Protocol::line($id, 'system.ping', $ts, $nonce, '{}', $key->sign(Protocol::signedBytes($id, 'system.ping', $ts, $nonce, '{}')));
};

$code = static fn(string $answer): string => (string) (json_decode($answer, true)['error']['code'] ?? json_decode($answer, true)['status'] ?? $answer);

switch ($argv[1] ?? '') {
    case 'raw':
        echo $send(($argv[2] ?? '') . "\n"), "\n";
        break;
    case 'forged':
        echo $code($send($signed(AgentKey::fromSeed(random_bytes(32)), time()))), "\n";
        break;
    case 'replay':
        $line = $signed(AgentKey::fromFile($argv[2]), time());
        echo $code($send($line)), ' ', $code($send($line)), "\n";
        break;
    case 'stale':
        echo $code($send($signed(AgentKey::fromFile($argv[2]), time() - 300))), "\n";
        break;
    case 'call':
        // call KEYFILE OP 'JSON ARGS' [ID]: a correctly signed request; prints the whole answer.
        $key = AgentKey::fromFile($argv[2]);
        $op = $argv[3] ?? 'system.ping';
        $args = Protocol::encodeArgs(json_decode($argv[4] ?? '{}', true) ?: []);
        $id = $argv[5] ?? Protocol::newId();
        $ts = time();
        $nonce = Protocol::newNonce();
        echo $send(Protocol::line($id, $op, $ts, $nonce, $args, $key->sign(Protocol::signedBytes($id, $op, $ts, $nonce, $args)))), "\n";
        break;
    default:
        fwrite(STDERR, "usage: probe.php raw LINE | forged | replay KEYFILE | stale KEYFILE | call KEYFILE OP ARGS [ID]\n");
        exit(2);
}
