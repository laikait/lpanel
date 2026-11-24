<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Operations\Cron\CronJobs;
use App\Modules\Operations\Cron\CronRules;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;

/** The User panel's cron jobs page: the account's scheduled commands. */
final class CronPages
{
    public function __construct(
        private readonly CronJobs $jobs,
        private readonly Subusers $subusers,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        return $this->page($request, $session, $identity);
    }

    public function add(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
        $input = ['schedule' => (string) $request->input('schedule', ''), 'command' => (string) $request->input('command', '')];
        [$id, $errors] = $this->jobs->add($identity, $request, $account, $input['schedule'], $input['command']);

        if ($id === null) {
            return $this->page($request, $session, $identity, ['errors' => $errors, 'old' => $input], 422);
        }

        PanelPage::notice($session, 'success', 'Operations.cron_added');

        return new RedirectResponse($this->router->url('user.cron'), 303);
    }

    public function remove(Request $request, Session $session, Identity $identity, string $id): Response
    {
        $account = $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
        [$ok, $why] = $this->jobs->remove($identity, $request, $account, (int) $id);
        PanelPage::notice($session, $ok ? 'success' : 'error', $ok ? 'Operations.cron_removed' : (string) $why);

        return new RedirectResponse($this->router->url('user.cron'), 303);
    }

    /** @param array<string, mixed> $extra */
    private function page(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $account = $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
        $jobs = \array_map(fn(array $j): array => $j + ['delete_url' => $this->router->url('user.cron.delete', ['id' => $j['id']])], $this->jobs->forAccount((int) $account['id']));

        return $this->pages->render($request, $session, $identity, 'user', 'Operations/cron', $extra + [
            'jobs' => $jobs,
            'max' => CronRules::MAX_JOBS,
            'min_minutes' => CronRules::MIN_MINUTES,
            'add_url' => $this->router->url('user.cron.add'),
            'suspended' => ($account['status'] ?? '') !== 'active',
            'errors' => [],
            'old' => [],
        ], title: 'Operations.cron_title', active: 'cron', status: $status);
    }
}
