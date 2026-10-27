<?php

declare(strict_types=1);

namespace App\Modules\Servers\Agent;

/**
 * The panel's Ed25519 operation-signing key (AGENT_PROTOCOL.md §1).
 *
 * A file, never the database: someone who reads the database must not be
 * able to sign operations for every server. The file holds the 32-byte seed
 * as base64url and is made by `php laika servers:keygen`. In production it is
 * /etc/lpanel/keys/operations.key, 0640 root:lpanel.
 */
final class OperationKey
{
    /** @var ?non-empty-string */
    private ?string $secret = null;

    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return $this->path !== '' && \is_file($this->path);
    }

    /** The base64url public key agents receive at enrollment. */
    public function publicKey(): string
    {
        return Protocol::b64(\sodium_crypto_sign_publickey_from_secretkey($this->secret()));
    }

    public function kid(): string
    {
        return Protocol::keyId(\sodium_crypto_sign_publickey_from_secretkey($this->secret()));
    }

    /** @return array{operation: string, kid: string, signature: string} */
    public function sign(string $operationJson): array
    {
        return Protocol::signOperation($this->secret(), $operationJson);
    }

    /**
     * Make a new key file. Refuses to replace one: every enrolled agent trusts
     * the current key, and a new one would make them refuse every operation.
     */
    public static function generate(string $path): void
    {
        if (\file_exists($path)) {
            throw new \RuntimeException(\sprintf('%s already exists. Replacing it would make every enrolled agent refuse the panel\'s operations.', $path));
        }

        $dir = \dirname($path);

        if (!\is_dir($dir) && !\mkdir($dir, 0o750, true) && !\is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Cannot create %s.', $dir));
        }

        $seed = \random_bytes(\SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $old = \umask(0o137);

        try {
            if (\file_put_contents($path, Protocol::b64($seed) . "\n", \LOCK_EX) === false) {
                throw new \RuntimeException(\sprintf('Cannot write %s.', $path));
            }
        } finally {
            \umask($old);
            \sodium_memzero($seed);
        }

        @\chmod($path, 0o640);
    }

    /** @return non-empty-string */
    private function secret(): string
    {
        if ($this->secret !== null) {
            return $this->secret;
        }

        if (!$this->exists()) {
            throw new \RuntimeException(\sprintf('The operation-signing key %s does not exist. Run `php laika servers:keygen`.', $this->path === '' ? '(Servers.operation_key is empty)' : $this->path));
        }

        $seed = Protocol::unb64(\trim((string) \file_get_contents($this->path)));

        if ($seed === null || \strlen($seed) !== \SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new \RuntimeException(\sprintf('%s does not hold a base64url Ed25519 seed.', $this->path));
        }

        return $this->secret = \sodium_crypto_sign_secretkey(\sodium_crypto_sign_seed_keypair($seed));
    }
}
