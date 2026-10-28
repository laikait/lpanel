<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Engine\Auth\Password;
use App\Engine\Database\ConnectionManager;
use App\Engine\Security\Secret;
use App\Modules\Identity\Users\UserStore;
use App\Modules\Operations\Store\Operation;
use App\Tests\Support\Browser;
use App\Tests\Support\OperationsTestCase;

/** The operations screens and API, from asking for an operation to reading its result. */
final class OperationsPageTest extends OperationsTestCase
{
    private const PASSWORD = 'a perfectly fine password';

    /** @param list<string> $roles */
    private function as(string $login, array $roles): Browser
    {
        $hash = $this->service(Password::class)->hash(new Secret(self::PASSWORD));
        $this->service(UserStore::class)->create($login, \ucfirst($login), null, $hash, $roles);
        $browser = $this->browser();
        $browser->post('/login', ['login' => $login, 'password' => self::PASSWORD]);

        return $browser;
    }

    /** The one operation queued so far. */
    private function only(): Operation
    {
        $all = $this->store()->page();
        self::assertCount(1, $all);

        return $all[0];
    }

    public function test_an_admin_runs_an_operation_from_the_server_page_and_reads_its_result(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $page = '/servers/' . $this->server()->uid;

        $before = $admin->get($page)->body();
        self::assertStringContainsString('Not measured yet', $before);
        self::assertStringContainsString('Measure now', $before);
        self::assertStringContainsString('name="op" value="service.list_status"', $before);

        $asked = $admin->post($page . '/operations', ['op' => 'system.metrics', 'op_version' => '1']);
        self::assertSame(303, $asked->status());
        $op = $this->only();
        self::assertSame(['system.metrics', Operation::QUEUED, 'Alice'], [$op->op, $op->status, $op->requestedByName]);

        // While it is under way, the page says so and watches it.
        $waiting = $admin->get($page)->body();
        self::assertStringContainsString('Measuring…', $waiting);
        self::assertStringContainsString('data-lp-watch="/api/v1/operations/' . $op->uid . '"', $waiting);
        self::assertStringContainsString('queued. The agent takes it', $waiting);

        // The agent does it.
        $this->claimed($agent->claim());
        self::assertSame(200, $agent->result($op->uid, 1, 'succeeded', self::example('system.metrics'))->status());

        $after = $admin->get($page)->body();
        self::assertStringContainsString('Measured', $after);
        self::assertStringContainsString('lp-gauges', $after);
        self::assertStringContainsString('<code>/</code>', $after, 'the disk is listed by its mount point');
        self::assertStringNotContainsString('data-lp-watch=', $after);

        $detail = $admin->get('/operations/' . $op->uid);
        self::assertSame(200, $detail->status());
        self::assertStringContainsString('Resource usage', $detail->body());
        self::assertStringContainsString('Raw result, as the agent sent it', $detail->body());
        self::assertStringContainsString('Run again', $detail->body());

        $list = $admin->get('/operations')->body();
        self::assertStringContainsString('Resource usage', $list);
        self::assertStringContainsString('web-01', $list);
        self::assertStringContainsString('Nothing matches', $admin->get('/operations?status=failed')->body());
    }

    public function test_the_service_card_explains_a_failed_check(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $op = $this->queue('service.list_status');
        $this->claimed($agent->claim());
        $agent->result($op->uid, 1, 'failed', null, 'adapter_unavailable', 'systemd could not be queried');

        $body = $admin->get('/servers/' . $this->server()->uid)->body();
        self::assertStringContainsString('The last check failed: <code>adapter_unavailable</code>', $body);
        self::assertStringContainsString('systemd could not be queried', $body);
    }

    public function test_a_queued_operation_is_cancelled_from_its_page(): void
    {
        $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $op = $this->queue('system.info');

        self::assertStringContainsString('Cancel this operation?', $admin->get('/operations/' . $op->uid)->body());
        self::assertSame(303, $admin->post('/operations/' . $op->uid . '/cancel')->status());
        self::assertSame(Operation::CANCELLED, $this->reload($op)->status);
        self::assertStringContainsString('Cancelled. No agent had taken it.', $admin->get('/operations/' . $op->uid)->body());
    }

    public function test_an_offline_agent_gets_no_buttons_and_no_work(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $this->service(ConnectionManager::class)->connection()->table('servers')->where('agent_id', $agent->agentId)
            ->update(['last_seen_at' => new \DateTimeImmutable('-1 hour', new \DateTimeZone('UTC'))]);
        $page = '/servers/' . $this->server()->uid;

        self::assertStringContainsString('Agent offline', $admin->get($page)->body());
        $admin->post($page . '/operations', ['op' => 'system.info', 'op_version' => '1']);
        self::assertStringContainsString('has not checked in recently', $admin->get($page)->body());
        self::assertSame([], $this->store()->page());
    }

    public function test_customers_see_no_operations(): void
    {
        $this->onlineAgent();
        $customer = $this->as('carl', ['customer']);

        self::assertSame(403, $customer->get('/operations')->status());
        self::assertSame(403, $customer->post('/servers/' . $this->server()->uid . '/operations', ['op' => 'system.info', 'op_version' => '1'])->status());
        self::assertSame(403, $customer->postJson('/api/v1/servers/' . $this->server()->uid . '/operations', ['op' => 'system.info'])->status());
        self::assertSame([], $this->store()->page());
    }

    public function test_the_api_queues_joins_reports_and_cancels(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $url = '/api/v1/servers/' . $this->server()->uid . '/operations';

        $first = $admin->postJson($url, ['op' => 'system.info']);
        self::assertSame(202, $first->status(), $first->body());
        $data = \json_decode($first->body(), true)['data'];
        self::assertSame(['system.info', 1, 'queued'], [$data['op'], $data['op_version'], $data['status']]);
        self::assertSame('/api/v1/operations/' . $data['id'], $first->header('Location'));

        $again = $admin->postJson($url, ['op' => 'system.info', 'op_version' => 1, 'payload' => new \stdClass()]);
        self::assertSame(200, $again->status(), 'an identical read-only request joins the first');
        self::assertSame($data['id'], \json_decode($again->body(), true)['data']['id']);

        $this->claimed($agent->claim());
        $agent->result($data['id'], 1, 'succeeded', self::example('system.info'));

        $shown = $admin->data('/api/v1/operations/' . $data['id']);
        self::assertSame('succeeded', $shown['status']);
        self::assertSame('trixie', $shown['result']['os']['version_codename']);
        self::assertSame(['queued', 'claimed', 'succeeded'], \array_column($shown['events'], 'to_status'));

        self::assertSame(1, \count($admin->data('/api/v1/operations?status=succeeded')));
        self::assertSame([], $admin->data('/api/v1/operations?status=active'));
        self::assertSame(15, \count($admin->data('/api/v1/operations/catalogue')));

        // An account operation is not asked for from a server: it has no account to act on.
        $direct = $admin->postJson($url, ['op' => 'account.delete', 'payload' => ['system_user' => 'lpabcdefgh']]);
        self::assertSame(422, $direct->status());
        self::assertSame('payload_invalid', \json_decode($direct->body(), true)['error']['code']);

        // A finished one cannot be cancelled; a queued one can.
        self::assertSame(409, $admin->postJson('/api/v1/operations/' . $data['id'] . '/cancel', [])->status());
        $metrics = \json_decode($admin->postJson($url, ['op' => 'system.metrics'])->body(), true)['data'];
        $cancelled = $admin->postJson('/api/v1/operations/' . $metrics['id'] . '/cancel', []);
        self::assertSame(202, $cancelled->status());
        self::assertSame('cancelled', \json_decode($cancelled->body(), true)['data']['status']);
    }

    public function test_the_api_refuses_what_cannot_be_asked(): void
    {
        $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $url = '/api/v1/servers/' . $this->server()->uid . '/operations';

        foreach ([
            'an operation the panel does not have' => [422, 'unknown_operation', ['op' => 'shell.exec']],
            'a payload field' => [422, 'payload_invalid', ['op' => 'system.info', 'payload' => ['command' => 'id']]],
            'a list as payload' => [422, 'payload_invalid', ['op' => 'system.info', 'payload' => []]],
            'a version the panel does not have' => [422, 'unknown_operation', ['op' => 'system.info', 'op_version' => 9]],
            'an unknown field' => [422, 'bad_request', ['op' => 'system.info', 'env' => ['PATH' => '/tmp']]],
        ] as $why => [$status, $code, $body]) {
            $response = $admin->postJson($url, $body);
            self::assertSame([$status, $code], [$response->status(), \json_decode($response->body(), true)['error']['code'] ?? null], $why);
        }

        self::assertSame([], $this->store()->page());
    }

    public function test_the_dashboard_counts_operations(): void
    {
        $agent = $this->onlineAgent();
        $admin = $this->as('alice', ['admin']);
        $this->queue('system.info');
        $failed = $this->queue('agent.health');
        $this->claimed($agent->claim(5));
        $agent->result($failed->uid, 1, 'failed', null, 'internal');

        $home = $admin->get('/')->body();
        self::assertMatchesRegularExpression('#<strong>1</strong><span>in progress</span>#', $home);
        self::assertMatchesRegularExpression('#<strong>1</strong><span>failed, 24 h</span>#', $home);
    }
}
