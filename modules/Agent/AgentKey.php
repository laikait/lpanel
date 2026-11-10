<?php

declare(strict_types=1);

namespace App\Modules\Agent;

/**
 * The panel's Ed25519 signing key for agent requests.
 *
 * On a server it is /etc/lpanel/agent.key: base64 of libsodium's 64-byte
 * secret key, mode 0600, readable only by the panel's user. The agent holds
 * only the public half (/etc/lpa/panel.pub), so reading the agent's files
 * never lets anyone sign.
 */
final class AgentKey
{
    /** @param non-empty-string $secret libsodium's 64-byte secret key */
    private function __construct(private readonly string $secret) {}

    public static function fromFile(string $path): self
    {
        $text = @\file_get_contents($path);

        if ($text === false) {
            throw new AgentUnavailable(\sprintf('The agent signing key %s cannot be read. Create it with: lpanel agent:keygen', $path));
        }

        return self::fromBase64(\trim($text));
    }

    public static function fromBase64(string $text): self
    {
        $secret = \base64_decode($text, true);

        if ($secret === false || $secret === '' || \strlen($secret) !== \SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new AgentUnavailable('The agent signing key is not a base64 Ed25519 secret key.');
        }

        return new self($secret);
    }

    /** For tests and the golden vectors: the key a 32-byte seed makes. */
    public static function fromSeed(string $seed): self
    {
        if ($seed === '' || \strlen($seed) !== \SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new \InvalidArgumentException('An Ed25519 seed is 32 bytes.');
        }

        return new self(\sodium_crypto_sign_secretkey(\sodium_crypto_sign_seed_keypair($seed)));
    }

    /**
     * A new key pair.
     *
     * @return array{secret: string, public: string} both base64
     */
    public static function generate(): array
    {
        $pair = \sodium_crypto_sign_keypair();

        return [
            'secret' => \base64_encode(\sodium_crypto_sign_secretkey($pair)),
            'public' => \base64_encode(\sodium_crypto_sign_publickey($pair)),
        ];
    }

    public function sign(string $message): string
    {
        return \base64_encode(\sodium_crypto_sign_detached($message, $this->secret));
    }

    public function publicKey(): string
    {
        return \base64_encode(\sodium_crypto_sign_publickey_from_secretkey($this->secret));
    }

    /** Never print the secret by accident. */
    public function __debugInfo(): array
    {
        return ['public' => $this->publicKey()];
    }
}
