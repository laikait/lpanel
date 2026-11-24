<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Session\Session;
use App\Modules\Operations\Tasks\Tasks;
use App\Modules\Panel\Ui\PanelPage;

/** The Admin panel's background tasks page: what the agent has done and is doing. */
final class TaskPages
{
    public function __construct(
        private readonly Tasks $tasks,
        private readonly PanelPage $pages,
    ) {}

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        $state = (string) $request->query('state', '');
        $state = \in_array($state, Tasks::STATES, true) ? $state : '';
        $list = $this->tasks->list($state, 100);
        $counts = [];

        foreach ($this->tasks->list('', 200) ?? [] as $t) {
            $counts[(string) $t['state']] = ($counts[(string) $t['state']] ?? 0) + 1;
        }

        return $this->pages->render($request, $session, $identity, 'admin', 'Operations/tasks', [
            'available' => $list !== null,
            'tasks' => $list ?? [],
            'state' => $state,
            'states' => Tasks::STATES,
            'counts' => $counts,
        ], title: 'Operations.tasks_title', active: 'tasks');
    }
}
