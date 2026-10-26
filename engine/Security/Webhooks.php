<?php

declare(strict_types=1);

namespace App\Engine\Security;

use App\Engine\Cache\AtomicStore;
use App\Engine\Cache\Cache;
use App\Engine\Cache\Stores\ArrayStore;
use App\Engine\Http\HttpException;
use App\Engine\Http\Request;

/**
 * Receiving webhooks: is this really from Stripe, and have we seen it before?
 *
 *     // config: security.webhooks.stripe = ['format' => 'stripe', 'secret' => Env::string('STRIPE_WEBHOOK_SECRET')]
 *     $routes->post('/webhooks/stripe', StripeWebhook::class)->meta(['csrf' => false]);
 *
 *     public function __invoke(Request $request, Webhooks $webhooks, Queue $queue): Response
 *     {
 *         $event = $webhooks->receive('stripe', $request);     // 400 unless signed by Stripe
 *
 *         if (!$event->duplicate) {
 *             $queue->push(new HandleStripeEvent($event->payload));
 *         }
 *
 *         return new Response('', 200);                        // at once; the work is queued
 *     }
 *
 * **Formats.** stripe (Stripe-Signature), github (X-Hub-Signature-256),
 * shopify (X-Shopify-Hmac-Sha256), standard (the Standard Webhooks spec that
 * Svix, Resend, Clerk and others send: webhook-id, webhook-timestamp,
 * webhook-signature), and hmac -- a header holding an HMAC of the body, for
 * everybody else, with the header, algorithm, encoding and prefix configurable.
 *
 * **The signature is over the raw body**, which is why this reads
 * Request::body() and never a parsed form; computed by Signer::hmac() and
 * compared in constant time. A secret may be a list, for rotation: any of
 * them will do.
 *
 * **Replays are refused twice over.** Where the format signs a timestamp
 * (stripe, standard), a delivery older or newer than the tolerance -- five
 * minutes -- is a 400. And every id is remembered for a day with the cache's
 * atomic add(), so the second delivery of an id comes back as a duplicate.
 * That needs a cache every process shares (file or database): the array
 * store forgets at the end of the request.
 *
 * **Without one, receive() refuses** rather than accept every delivery as
 * new. Before, a cache that could not remember -- the array store, which is
 * the default, or none at all -- silently turned de-duplication off, and for
 * github, shopify and hmac, which sign no timestamp, nothing else stopped a
 * captured delivery being sent again a year later. Bootstrap asks for the
 * refusal and never meets it: when the application's cache forgets, it hands
 * this a file store of its own, so a real delivery is never refused for how
 * the cache is configured. A test that builds this directly decides for itself.
 */
final class Webhooks
{
    public const TOLERANCE = 300;

    public const REMEMBER = 86400;

    /**
     * @param array<array-key, mixed> $sources security.webhooks: name => settings
     * @param ?\Closure(): int         $clock
     */
    public function __construct(
        private readonly array $sources,
        private readonly ?Cache $cache = null,
        private readonly ?\Closure $clock = null,
        /** Refuse to receive when the cache cannot remember an id across requests. */
        private readonly bool $requireSharedCache = false,
    ) {}

    /**
     * Whether a delivery seen by one request is remembered by the next.
     *
     * Atomic and outliving the request: the array store is atomic, but it is
     * gone when the request ends, so to a replay every delivery is the first.
     */
    public function remembersAcrossRequests(): bool
    {
        $store = $this->cache?->store();

        return $store instanceof AtomicStore && !$store instanceof ArrayStore;
    }

    /**
     * Verify a delivery, and say whether it was seen before.
     *
     * @throws HttpException 400 for a signature that does not check out or a stale timestamp
     * @throws SecurityException for a source that is not configured
     */
    public function receive(string $source, Request $request): Webhook
    {
        $settings = $this->sources[$source] ?? null;

        if (!\is_array($settings)) {
            throw SecurityException::unknownWebhook($source, \array_map(\strval(...), \array_keys($this->sources)));
        }

        $format = \is_string($settings['format'] ?? null) ? $settings['format'] : $source;
        $secrets = \array_values(\array_filter((array) ($settings['secret'] ?? []), static fn(mixed $secret): bool => \is_string($secret) && $secret !== ''));

        if ($secrets === []) {
            throw SecurityException::webhookWithoutSecret($source);
        }

        if ($this->requireSharedCache && !$this->remembersAcrossRequests()) {
            throw SecurityException::webhookWithoutReplayProtection($source);
        }

        $tolerance = \is_int($settings['tolerance'] ?? null) ? $settings['tolerance'] : self::TOLERANCE;
        $body = $request->body();

        [$id, $type] = match ($format) {
            'stripe' => $this->stripe($request, $body, $secrets, $tolerance),
            'github' => $this->github($request, $body, $secrets),
            'shopify' => $this->shopify($request, $body, $secrets),
            'standard' => $this->standard($request, $body, $secrets, $tolerance),
            'hmac' => $this->hmac($request, $body, $secrets, $settings),
            default => throw SecurityException::unknownWebhookFormat($source, $format),
        };

        $payload = \json_decode($body, true);
        $remember = \is_int($settings['remember'] ?? null) ? $settings['remember'] : self::REMEMBER;

        return new Webhook(
            $source,
            $id,
            $type,
            \is_array($payload) ? $payload : [],
            $body,
            $id !== null && !$this->firstTime($source, $id, $remember),
        );
    }

    /**
     * Forget that an id was received, so the sender's retry is processed: for
     * when queuing the work failed after receive() marked it seen.
     */
    public function release(Webhook $webhook): void
    {
        if ($webhook->id !== null) {
            $this->cache?->delete(self::key($webhook->source, $webhook->id));
        }
    }

    /**
     * @param list<string> $secrets
     *
     * @return array{?string, ?string}
     */
    private function stripe(Request $request, string $body, array $secrets, int $tolerance): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (\explode(',', (string) $request->header('Stripe-Signature', '')) as $part) {
            [$key, $value] = \array_pad(\explode('=', \trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        $this->assertFresh($timestamp, $tolerance);
        $this->assertSigned($secrets, static fn(string $secret): string => Signer::hmac('sha256', $timestamp . '.' . $body, $secret), $signatures);

        return self::idAndType($body, 'id', 'type');
    }

    /**
     * @param list<string> $secrets
     *
     * @return array{?string, ?string}
     */
    private function github(Request $request, string $body, array $secrets): array
    {
        $given = (string) $request->header('X-Hub-Signature-256', '');
        $this->assertSigned($secrets, static fn(string $secret): string => 'sha256=' . Signer::hmac('sha256', $body, $secret), [$given]);

        return [$request->header('X-GitHub-Delivery'), $request->header('X-GitHub-Event')];
    }

    /**
     * @param list<string> $secrets
     *
     * @return array{?string, ?string}
     */
    private function shopify(Request $request, string $body, array $secrets): array
    {
        $given = (string) $request->header('X-Shopify-Hmac-Sha256', '');
        $this->assertSigned($secrets, static fn(string $secret): string => \base64_encode(Signer::hmac('sha256', $body, $secret, true)), [$given]);

        return [$request->header('X-Shopify-Webhook-Id') ?? $request->header('X-Shopify-Event-Id'), $request->header('X-Shopify-Topic')];
    }

    /**
     * Standard Webhooks: "v1,<base64>" signatures, space separated, over
     * "<id>.<timestamp>.<body>", with a "whsec_<base64>" secret.
     *
     * @param list<string> $secrets
     *
     * @return array{?string, ?string}
     */
    private function standard(Request $request, string $body, array $secrets, int $tolerance): array
    {
        $id = (string) $request->header('webhook-id', '');
        $timestamp = $request->header('webhook-timestamp');
        $this->assertFresh($timestamp, $tolerance);

        $signatures = [];

        foreach (\explode(' ', (string) $request->header('webhook-signature', '')) as $signature) {
            if (\str_starts_with($signature, 'v1,')) {
                $signatures[] = \substr($signature, 3);
            }
        }

        $this->assertSigned($secrets, static function (string $secret) use ($id, $timestamp, $body): string {
            $key = \base64_decode(\str_starts_with($secret, 'whsec_') ? \substr($secret, 6) : $secret, true);

            return \base64_encode(Signer::hmac('sha256', $id . '.' . $timestamp . '.' . $body, $key === false ? $secret : $key, true));
        }, $signatures);

        [, $type] = self::idAndType($body, 'id', 'type');

        return [$id === '' ? null : $id, $type];
    }

    /**
     * @param list<string>            $secrets
     * @param array<array-key, mixed> $settings
     *
     * @return array{?string, ?string}
     */
    private function hmac(Request $request, string $body, array $secrets, array $settings): array
    {
        $option = static fn(string $key, string $default): string => \is_string($settings[$key] ?? null) ? $settings[$key] : $default;
        $algorithm = $option('algorithm', 'sha256');
        $base64 = $option('encoding', 'hex') === 'base64';
        $prefix = $option('prefix', '');

        if (!\in_array($algorithm, \hash_hmac_algos(), true)) {
            throw SecurityException::unknownWebhookFormat('hmac', $algorithm);
        }

        $given = (string) $request->header($option('header', 'X-Signature'), '');
        $this->assertSigned($secrets, static function (string $secret) use ($algorithm, $body, $base64, $prefix): string {
            $mac = Signer::hmac($algorithm, $body, $secret, $base64);

            return $prefix . ($base64 ? \base64_encode($mac) : $mac);
        }, [$given]);

        $idHeader = $option('id_header', '');
        $typeHeader = $option('type_header', '');

        return [
            $idHeader === '' ? null : $request->header($idHeader),
            $typeHeader === '' ? null : $request->header($typeHeader),
        ];
    }

    /**
     * @param list<string>               $secrets
     * @param \Closure(string): string   $expected the signature a secret makes
     * @param list<string>               $given
     */
    private function assertSigned(array $secrets, \Closure $expected, array $given): void
    {
        foreach ($secrets as $secret) {
            $signature = $expected($secret);

            foreach ($given as $candidate) {
                if ($candidate !== '' && Signer::matches($signature, $candidate)) {
                    return;
                }
            }
        }

        throw HttpException::badRequest('The webhook signature does not match.');
    }

    private function assertFresh(?string $timestamp, int $tolerance): void
    {
        $now = $this->clock !== null ? ($this->clock)() : \time();

        if ($timestamp === null || !\ctype_digit($timestamp) || \abs($now - (int) $timestamp) > $tolerance) {
            throw HttpException::badRequest('The webhook timestamp is missing or outside the tolerance.');
        }
    }

    private function firstTime(string $source, string $id, int $remember): bool
    {
        if ($this->cache === null) {
            return true;
        }

        $store = $this->cache->store();

        // A store that cannot add atomically cannot tell two deliveries apart.
        if (!$store instanceof AtomicStore) {
            return true;
        }

        return $store->add($this->cache->qualify(self::key($source, $id)), 1, $remember);
    }

    private static function key(string $source, string $id): string
    {
        return 'webhook.' . $source . '.' . \hash('sha256', $id);
    }

    /** @return array{?string, ?string} */
    private static function idAndType(string $body, string $idKey, string $typeKey): array
    {
        $payload = \json_decode($body, true);

        if (!\is_array($payload)) {
            return [null, null];
        }

        return [
            \is_string($payload[$idKey] ?? null) ? $payload[$idKey] : null,
            \is_string($payload[$typeKey] ?? null) ? $payload[$typeKey] : null,
        ];
    }
}
