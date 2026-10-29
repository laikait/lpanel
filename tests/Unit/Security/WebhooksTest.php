<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Engine\Cache\Cache;
use App\Engine\Cache\Stores\ArrayStore;
use App\Engine\Cache\Stores\FileStore;
use App\Engine\Http\HttpException;
use App\Engine\Http\Request;
use App\Engine\Security\SecurityException;
use App\Engine\Security\Webhooks;
use App\Tests\Support\TestCase;

final class WebhooksTest extends TestCase
{
    private const NOW = 1614265330;

    /** @param array<array-key, mixed> $sources */
    private static function webhooks(array $sources, ?Cache $cache = null): Webhooks
    {
        return new Webhooks($sources, $cache ?? new Cache(new ArrayStore()), static fn(): int => self::NOW);
    }

    /** @param array<string, string> $headers */
    private static function post(string $body, array $headers): Request
    {
        return Request::create('POST', '/webhooks', ['body' => $body, 'headers' => $headers]);
    }

    /** GitHub's own example: https://docs.github.com/webhooks/using-webhooks/validating-webhook-deliveries */
    public function test_githubs_documented_example(): void
    {
        $webhooks = self::webhooks(['github' => ['secret' => "It's a Secret to Everybody"]]);

        $event = $webhooks->receive('github', self::post('Hello, World!', [
            'X-Hub-Signature-256' => 'sha256=757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17',
            'X-GitHub-Delivery' => '72d3162e-cc78-11e3-81ab-4c9367dc0958',
            'X-GitHub-Event' => 'push',
        ]));

        self::assertSame('72d3162e-cc78-11e3-81ab-4c9367dc0958', $event->id);
        self::assertSame('push', $event->type);
        self::assertFalse($event->duplicate);
    }

    /** The Standard Webhooks specification's example (as Svix publishes it). */
    public function test_the_standard_webhooks_example(): void
    {
        $webhooks = self::webhooks(['resend' => ['format' => 'standard', 'secret' => 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw']]);

        $event = $webhooks->receive('resend', self::post('{"test": 2432232314}', [
            'webhook-id' => 'msg_p5jXN8AQM9LWM0D4loKWxJek',
            'webhook-timestamp' => '1614265330',
            'webhook-signature' => 'v1,bm9ldHUzMDA= v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
        ]));

        self::assertSame('msg_p5jXN8AQM9LWM0D4loKWxJek', $event->id);
        self::assertSame(['test' => 2432232314], $event->payload);
    }

    public function test_stripe(): void
    {
        $secret = 'whsec_test';
        $body = '{"id":"evt_1","type":"invoice.paid","data":{"object":{"id":"in_1"}}}';
        $signature = \hash_hmac('sha256', self::NOW . '.' . $body, $secret);
        $webhooks = self::webhooks(['stripe' => ['secret' => ['whsec_old', $secret]]]);

        $event = $webhooks->receive('stripe', self::post($body, ['Stripe-Signature' => 't=' . self::NOW . ',v1=deadbeef,v1=' . $signature . ',v0=ignored']));

        self::assertSame('evt_1', $event->id);
        self::assertSame('invoice.paid', $event->type);
        self::assertSame('in_1', $event->payload['data']['object']['id']);
    }

    public function test_stripe_outside_the_tolerance_is_refused(): void
    {
        $body = '{"id":"evt_1"}';
        $old = self::NOW - 301;
        $webhooks = self::webhooks(['stripe' => ['secret' => 's']]);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('outside the tolerance');

        $webhooks->receive('stripe', self::post($body, ['Stripe-Signature' => 't=' . $old . ',v1=' . \hash_hmac('sha256', $old . '.' . $body, 's')]));
    }

    public function test_shopify(): void
    {
        $body = '{"id":820982911946154508}';
        $webhooks = self::webhooks(['shop' => ['format' => 'shopify', 'secret' => 'hush']]);

        $event = $webhooks->receive('shop', self::post($body, [
            'X-Shopify-Hmac-Sha256' => \base64_encode(\hash_hmac('sha256', $body, 'hush', true)),
            'X-Shopify-Webhook-Id' => 'b54557e4-bdd9-4b37-8a5f-bf7d70bcd043',
            'X-Shopify-Topic' => 'orders/create',
        ]));

        self::assertSame('orders/create', $event->type);
    }

    public function test_a_configurable_hmac(): void
    {
        $body = 'payload';
        $webhooks = self::webhooks(['acme' => [
            'format' => 'hmac',
            'secret' => 'k',
            'header' => 'X-Acme-Signature',
            'algorithm' => 'sha512',
            'encoding' => 'base64',
            'prefix' => 'sha512=',
            'id_header' => 'X-Acme-Id',
        ]]);

        $event = $webhooks->receive('acme', self::post($body, [
            'X-Acme-Signature' => 'sha512=' . \base64_encode(\hash_hmac('sha512', $body, 'k', true)),
            'X-Acme-Id' => '9',
        ]));

        self::assertSame('9', $event->id);
        self::assertSame([], $event->payload, 'A body that is not JSON has no payload, but still its raw body.');
        self::assertSame('payload', $event->body);
    }

    public function test_a_wrong_or_missing_signature_is_a_400(): void
    {
        $webhooks = self::webhooks(['github' => ['secret' => 'right']]);

        foreach ([['X-Hub-Signature-256' => 'sha256=' . \hash_hmac('sha256', 'x', 'wrong')], []] as $headers) {
            try {
                $webhooks->receive('github', self::post('x', $headers));
                self::fail('Accepted.');
            } catch (HttpException $e) {
                self::assertSame(400, $e->status());
            }
        }
    }

    public function test_the_same_delivery_twice_is_a_duplicate_until_released(): void
    {
        $webhooks = self::webhooks(['github' => ['secret' => 's']]);
        $request = self::post('{}', ['X-Hub-Signature-256' => 'sha256=' . \hash_hmac('sha256', '{}', 's'), 'X-GitHub-Delivery' => 'd-1']);

        self::assertFalse($webhooks->receive('github', $request)->duplicate);
        $again = $webhooks->receive('github', $request);
        self::assertTrue($again->duplicate);

        $webhooks->release($again);
        self::assertFalse($webhooks->receive('github', $request)->duplicate);
    }

    /**
     * A cache that forgets between requests makes every delivery the first
     * one. The array store is atomic, so the old check let it through; and
     * github signs no timestamp, so nothing else would stop a replay a year
     * later. Asked to, receive() refuses instead of pretending.
     */
    public function test_a_cache_that_forgets_between_requests_is_refused_rather_than_trusted(): void
    {
        $sources = ['github' => ['secret' => 's']];
        $request = self::post('{}', ['X-Hub-Signature-256' => 'sha256=' . \hash_hmac('sha256', '{}', 's'), 'X-GitHub-Delivery' => 'd-1']);

        foreach ([null, new Cache(new ArrayStore())] as $cache) {
            $webhooks = new Webhooks($sources, $cache, static fn(): int => self::NOW, requireSharedCache: true);

            self::assertFalse($webhooks->remembersAcrossRequests());

            try {
                $webhooks->receive('github', $request);
                self::fail('a delivery was received with no replay protection');
            } catch (SecurityException $e) {
                self::assertStringContainsString('CACHE_STORE=file', $e->getMessage());
            }
        }

        $directory = \sys_get_temp_dir() . '/webhook-cache-' . \bin2hex(\random_bytes(4));

        try {
            $shared = new Webhooks($sources, new Cache(new FileStore($directory)), static fn(): int => self::NOW, requireSharedCache: true);

            self::assertTrue($shared->remembersAcrossRequests());
            self::assertFalse($shared->receive('github', $request)->duplicate);
            self::assertTrue($shared->receive('github', $request)->duplicate);
        } finally {
            (new FileStore($directory))->flush();
            @\rmdir($directory);
        }
    }

    public function test_configuration_mistakes_are_named(): void
    {
        foreach ([
            [[], 'There is no "x" webhook'],
            [['x' => ['format' => 'github']], 'has no secret'],
            [['x' => ['format' => 'paypal', 'secret' => 's']], 'format "paypal"'],
        ] as [$sources, $message]) {
            try {
                self::webhooks($sources)->receive('x', self::post('', []));
                self::fail('Accepted: ' . $message);
            } catch (SecurityException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }
}
