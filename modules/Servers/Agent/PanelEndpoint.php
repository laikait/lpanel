<?php

declare(strict_types=1);

namespace App\Modules\Servers\Agent;

/**
 * Where agents reach this panel, and the pin they check its TLS key against:
 * the two values in the `lpa enroll` command an administrator copies.
 *
 * The pin is given (Servers.panel_pin) or worked out from the certificate
 * panel Nginx serves (Servers.tls_certificate): sha256/ and the base64url
 * SHA-256 of the certificate's SubjectPublicKeyInfo, exactly as the agent's
 * keys.SPKIPin computes it.
 */
final class PanelEndpoint
{
    public function __construct(
        private readonly string $url,
        private readonly string $pin,
        private readonly string $certificatePath,
    ) {}

    /** https://panel.example:8443, or null when not configured. */
    public function url(): ?string
    {
        $url = \rtrim($this->url, '/');

        return \preg_match('~^https://[^\s/?#]+(/[^\s?#]*)?$~', $url) === 1 ? $url : null;
    }

    public function pin(): ?string
    {
        if ($this->pin !== '') {
            return \preg_match('#^sha256/[A-Za-z0-9_-]{43}$#', $this->pin) === 1 ? $this->pin : null;
        }

        if ($this->certificatePath === '' || !\is_readable($this->certificatePath)) {
            return null;
        }

        return self::pinOfPem((string) \file_get_contents($this->certificatePath));
    }

    public static function pinOfPem(string $pem): ?string
    {
        $key = @\openssl_pkey_get_public($pem);
        $details = $key === false ? false : \openssl_pkey_get_details($key);

        if (!\is_array($details) || !\is_string($details['key'] ?? null)) {
            return null;
        }

        // The details' "key" is the SubjectPublicKeyInfo as PEM.
        $spki = \base64_decode((string) \preg_replace('/-----[^-]+-----|\s+/', '', $details['key']), true);

        return $spki === false ? null : 'sha256/' . Protocol::b64(\hash('sha256', $spki, true));
    }

    /**
     * What is still missing before servers can be added, in words an administrator can act on.
     *
     * @return list<string>
     */
    public function problems(OperationKey $key): array
    {
        $problems = [];

        if ($this->url() === null) {
            $problems[] = 'Set LPANEL_AGENT_URL to the https:// address agents reach this panel on (panel Nginx, port 8443).';
        }

        if ($this->pin() === null) {
            $problems[] = 'Set LPANEL_TLS_CERTIFICATE to panel Nginx\'s certificate file, or LPANEL_AGENT_PIN to its sha256/ pin.';
        }

        if (!$key->exists()) {
            $problems[] = 'Create the operation-signing key: php laika servers:keygen';
        }

        return $problems;
    }
}
