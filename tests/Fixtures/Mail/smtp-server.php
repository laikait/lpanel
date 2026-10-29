<?php

declare(strict_types=1);

/*
 * A scripted SMTP server for SmtpTransportTest, one connection then exit:
 *
 *   php smtp-server.php <port-file> <log-file> <mode>
 *
 * It listens on a free port and writes the port to <port-file>, then appends
 * every line the client sends to <log-file>. Modes:
 *
 *   plain        no STARTTLS; AUTH PLAIN and LOGIN accepted
 *   login-only   no STARTTLS; only AUTH LOGIN offered
 *   bad-auth     no STARTTLS; every AUTH refused with 535
 *   bad-rcpt     RCPT TO refused with 550
 */

$arguments = isset($_SERVER['argv']) && is_array($_SERVER['argv']) ? $_SERVER['argv'] : [];
[, $portFile, $logFile, $mode] = $arguments + [null, null, null, 'plain'];

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

if ($server === false) {
    exit(1);
}

$name = (string) stream_socket_get_name($server, false);
file_put_contents((string) $portFile, substr($name, strrpos($name, ':') + 1));

$client = @stream_socket_accept($server, 10);

if ($client === false) {
    exit(1);
}

$say = static function (string $line) use ($client): void {
    fwrite($client, $line . "\r\n");
};
$log = static function (string $line) use ($logFile): void {
    file_put_contents((string) $logFile, $line . "\n", FILE_APPEND);
};

$say('220 fixture.test ESMTP');

while (($line = fgets($client)) !== false) {
    $line = rtrim($line, "\r\n");
    $log($line);
    $verb = strtoupper((string) strtok($line, ' '));

    switch ($verb) {
        case 'EHLO':
            $say('250-fixture.test');
            $say($mode === 'login-only' ? '250-AUTH LOGIN' : '250-AUTH PLAIN LOGIN');
            $say('250 8BITMIME');
            break;

        case 'AUTH':
            if ($mode === 'bad-auth') {
                $say('535 5.7.8 Authentication credentials invalid');
                break;
            }

            if (str_starts_with(strtoupper($line), 'AUTH LOGIN')) {
                $say('334 VXNlcm5hbWU6');
                $log((string) rtrim((string) fgets($client), "\r\n"));
                $say('334 UGFzc3dvcmQ6');
                $log((string) rtrim((string) fgets($client), "\r\n"));
            }

            $say('235 2.7.0 Authentication successful');
            break;

        case 'MAIL':
            $say('250 2.1.0 Ok');
            break;

        case 'RCPT':
            $say($mode === 'bad-rcpt' ? '550 5.1.1 No such user' : '250 2.1.5 Ok');
            break;

        case 'DATA':
            $say('354 End data with <CR><LF>.<CR><LF>');

            while (($data = fgets($client)) !== false) {
                $log('DATA| ' . rtrim($data, "\r\n"));

                if ($data === ".\r\n") {
                    break;
                }
            }

            $say('250 2.0.0 Ok: queued');
            break;

        case 'QUIT':
            $say('221 2.0.0 Bye');
            fclose($client);

            exit(0);

        default:
            $say('502 5.5.2 Not implemented');
    }
}
