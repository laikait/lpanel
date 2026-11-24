<?php

declare(strict_types=1);

namespace App\Modules\Operations\Alerts;

use App\Engine\Http\Client\Client;
use App\Engine\Http\Client\HttpClientException;
use App\Engine\Mail\Mailer;
use App\Engine\Mail\Message;

/**
 * Hands one alert to one channel. Nothing here throws: how it went is the
 * answer, because an alert that cannot be told must never break what raised it.
 *
 * Every web address is https and must resolve to a public address (Client's
 * publicOnly): an alert cannot be pointed at this server itself, at its
 * network or at a cloud metadata endpoint, and a redirect is checked the same
 * way. A request waits eight seconds at most.
 */
final class Delivery
{
    private const WAIT = 8.0;

    public function __construct(
        private readonly Mailer $mailer,
        private Client $http,
    ) {}

    /** For tests: a client with a fake transport. */
    public function useClient(Client $http): void
    {
        $this->http = $http;
    }

    /**
     * @param array<string, mixed> $channel as AlertChannels::open() gives it
     * @param array{category: string, severity: string, title: string, detail: string, at: string} $alert
     *
     * @return array{0: bool, 1: string} whether it was delivered, and if not why
     */
    public function send(array $channel, array $alert): array
    {
        $config = (array) ($channel['config'] ?? []);

        try {
            return match ((string) $channel['kind']) {
                'email' => $this->email((string) ($config['to'] ?? ''), $alert),
                'telegram' => $this->telegram((string) ($config['token'] ?? ''), (string) ($config['chat'] ?? ''), $alert),
                'slack' => $this->slack((string) ($config['url'] ?? ''), $alert),
                'webhook' => $this->webhook((string) ($config['url'] ?? ''), (string) ($config['signing_key'] ?? ''), $alert),
                default => [false, 'unknown channel'],
            };
        } catch (\Throwable $e) {
            return [false, self::clean($e instanceof HttpClientException ? $e->getMessage() : $e::class . ': ' . $e->getMessage(), $config)];
        }
    }

    /** @param array{category: string, severity: string, title: string, detail: string, at: string} $alert */
    public static function text(array $alert): string
    {
        $head = \strtoupper($alert['severity']) . ': ' . $alert['title'];

        return $head . ($alert['detail'] === '' ? '' : "\n\n" . $alert['detail']) . "\n\n" . self::host() . ', ' . $alert['at'] . ' UTC';
    }

    public static function host(): string
    {
        $h = \gethostname();

        return \is_string($h) && $h !== '' ? $h : 'server';
    }

    /**
     * @param array{category: string, severity: string, title: string, detail: string, at: string} $alert
     *
     * @return array{0: bool, 1: string}
     */
    private function email(string $to, array $alert): array
    {
        if ($to === '') {
            return [false, 'no address'];
        }

        $this->mailer->send(Message::create()
            ->to($to)
            ->subject('[' . self::host() . '] ' . \strtoupper($alert['severity']) . ': ' . \str_replace(["\r", "\n"], ' ', $alert['title']))
            ->text(self::text($alert)));

        return [true, ''];
    }

    /**
     * @param array{category: string, severity: string, title: string, detail: string, at: string} $alert
     *
     * @return array{0: bool, 1: string}
     */
    private function telegram(string $token, string $chat, array $alert): array
    {
        $r = $this->client()->asForm()->post('https://api.telegram.org/bot' . $token . '/sendMessage', [
            'chat_id' => $chat,
            'text' => \mb_substr(self::text($alert), 0, 4000),
            'disable_web_page_preview' => 'true',
        ]);

        return $r->successful() ? [true, ''] : [false, 'Telegram answered ' . $r->status() . ': ' . \mb_substr((string) ($r->json('description') ?? ''), 0, 150)];
    }

    /**
     * @param array{category: string, severity: string, title: string, detail: string, at: string} $alert
     *
     * @return array{0: bool, 1: string}
     */
    private function slack(string $url, array $alert): array
    {
        $icon = match ($alert['severity']) {
            'critical' => ':rotating_light:',
            'warning' => ':warning:',
            default => ':information_source:',
        };
        $r = $this->client()->post($url, ['text' => $icon . ' *' . $alert['title'] . '*' . ($alert['detail'] === '' ? '' : "\n" . $alert['detail']) . "\n_" . self::host() . ', ' . $alert['at'] . ' UTC_']);

        return $r->successful() ? [true, ''] : [false, 'the webhook answered ' . $r->status()];
    }

    /**
     * The alert as JSON; with a signing key, X-LPanel-Signature is
     * "sha256=" and the HMAC-SHA256 of the body, so the receiver can tell it
     * came from here.
     *
     * @param array{category: string, severity: string, title: string, detail: string, at: string} $alert
     *
     * @return array{0: bool, 1: string}
     */
    private function webhook(string $url, string $key, array $alert): array
    {
        $body = \json_encode([
            'source' => 'lpanel',
            'host' => self::host(),
            'category' => $alert['category'],
            'severity' => $alert['severity'],
            'title' => $alert['title'],
            'detail' => $alert['detail'],
            'time' => \gmdate('c', (int) \strtotime($alert['at'] . ' UTC')),
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $client = $this->client()->withHeaders(['Content-Type' => 'application/json'] + ($key === '' ? [] : ['X-LPanel-Signature' => 'sha256=' . \hash_hmac('sha256', $body, $key)]));
        $r = $client->post($url, $body);

        return $r->successful() ? [true, ''] : [false, 'the webhook answered ' . $r->status()];
    }

    private function client(): Client
    {
        return $this->http->timeout(self::WAIT)->publicOnly();
    }

    /**
     * An error must not carry a secret into the history: the bot token sits in
     * the address, and an exception message may quote an address.
     *
     * @param array<string, mixed> $config
     */
    private static function clean(string $message, array $config): string
    {
        foreach (['token', 'url', 'signing_key'] as $k) {
            if (isset($config[$k]) && \is_string($config[$k]) && $config[$k] !== '') {
                $message = \str_replace($config[$k], '…', $message);
            }
        }

        return \mb_substr($message, 0, 300);
    }
}
