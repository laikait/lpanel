<?php

declare(strict_types=1);

namespace App\Modules\Files\Ssh;

/**
 * Makes an Ed25519 key pair for someone who has none: the public half is
 * kept, the private half is shown once, in OpenSSH's own format, and never
 * stored. It has no passphrase; the page says how to add one
 * (ssh-keygen -p -f <file>).
 */
final class SshKeyGen
{
    /** @return array{public: string, private: string} the public key's base64 blob, and the private key file */
    public static function ed25519(string $comment): array
    {
        $pair = \sodium_crypto_sign_keypair();
        $public = \sodium_crypto_sign_publickey($pair);
        // libsodium's secret key is the seed and the public key, as OpenSSH keeps it.
        $secret = \sodium_crypto_sign_secretkey($pair);
        \sodium_memzero($pair);

        $blob = self::string('ssh-ed25519') . self::string($public);
        $check = \random_bytes(4);
        $private = $check . $check
            . self::string('ssh-ed25519') . self::string($public) . self::string($secret) . self::string($comment);
        \sodium_memzero($secret);

        // Padded to the cipher's block size (8 for "none") with 1, 2, 3...
        for ($i = 1; \strlen($private) % 8 !== 0; $i++) {
            $private .= \chr($i);
        }

        $file = "openssh-key-v1\0"
            . self::string('none') . self::string('none') . self::string('')
            . \pack('N', 1) . self::string($blob) . self::string($private);
        \sodium_memzero($private);

        $pem = "-----BEGIN OPENSSH PRIVATE KEY-----\n"
            . \chunk_split(\base64_encode($file), 70, "\n")
            . "-----END OPENSSH PRIVATE KEY-----\n";
        \sodium_memzero($file);

        return ['public' => \base64_encode($blob), 'private' => $pem];
    }

    private static function string(string $s): string
    {
        return \pack('N', \strlen($s)) . $s;
    }
}
