<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Mail\MailActions;
use App\Modules\Mail\Mailboxes;
use App\Modules\Mail\MailOutcome;
use App\Modules\Mail\MailQueue;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;

/**
 * The Email pages beside the main ones.
 *
 * User panel: the account's spam settings (when spam goes to Junk or is
 * deleted, allowed and blocked senders), and delivery tracking of its own
 * mail.
 *
 * Admin panel: Exim's queue (search, view, deliver, freeze, thaw, remove)
 * and delivery tracking of every message.
 */
final class MailToolPages
{
    public function __construct(
        private readonly Mailboxes $mail,
        private readonly MailActions $actions,
        private readonly MailQueue $queue,
        private readonly Subusers $subusers,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    /** @return list<array{key: string, label: string, url: string}> */
    public static function userTabs(Router $router): array
    {
        return [
            ['key' => 'mailboxes', 'label' => 'Mail.tab_mailboxes', 'url' => $router->url('user.mail')],
            ['key' => 'spam', 'label' => 'Mail.tab_spam', 'url' => $router->url('user.mail.spam')],
            ['key' => 'track', 'label' => 'Mail.tab_track', 'url' => $router->url('user.mail.track')],
        ];
    }

    /** @return list<array{key: string, label: string, url: string}> */
    public static function adminTabs(Router $router): array
    {
        return [
            ['key' => 'overview', 'label' => 'Mail.tab_overview', 'url' => $router->url('admin.mail')],
            ['key' => 'queue', 'label' => 'Mail.tab_queue', 'url' => $router->url('admin.mail.queue')],
            ['key' => 'track', 'label' => 'Mail.tab_track', 'url' => $router->url('admin.mail.track')],
        ];
    }

    // -------------------------------------------------------------------------
    // User panel

    public function spam(Request $request, Session $session, Identity $identity): Response
    {
        return $this->spamPage($request, $session, $identity);
    }

    public function saveSpam(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $input = [
            'junk' => $request->input('junk_on') === '1' ? (string) $request->input('junk', '') : '',
            'delete' => $request->input('delete_on') === '1' ? (string) $request->input('delete', '') : '',
            'allow' => (string) $request->input('allow', ''),
            'block' => (string) $request->input('block', ''),
        ];
        [$outcome, $errors] = $this->actions->setSpam($identity, $request, $account, $input);

        if ($errors !== []) {
            return $this->spamPage($request, $session, $identity, ['errors' => $errors, 'old' => [
                'junk_on' => $request->input('junk_on') === '1', 'junk' => (string) $request->input('junk', ''),
                'delete_on' => $request->input('delete_on') === '1', 'delete' => (string) $request->input('delete', ''),
                'allow' => $input['allow'], 'block' => $input['block'],
            ]], 422);
        }

        match ($outcome->status) {
            MailOutcome::REFUSED => PanelPage::notice($session, 'error', (string) $outcome->message),
            MailOutcome::FAILED => PanelPage::notice($session, 'error', 'Mail.refused'),
            MailOutcome::PENDING => PanelPage::notice($session, 'warning', 'Mail.pending'),
            default => PanelPage::notice($session, 'success', 'Mail.spam_saved'),
        };

        return new RedirectResponse($this->router->url('user.mail.spam'), 303);
    }

    /** @param array<string, mixed> $extra */
    private function spamPage(Request $request, Session $session, Identity $identity, array $extra = [], int $status = 200): Response
    {
        $account = $this->account($identity);
        $spam = $this->mail->spam((int) $account['id']);

        return $this->pages->render($request, $session, $identity, 'user', '@Mail/spam', $extra + [
            'old' => [
                'junk_on' => $spam['junk'] > 0, 'junk' => self::score($spam['junk'] ?: Mailboxes::SPAM_DEFAULTS['junk']),
                'delete_on' => $spam['delete'] > 0, 'delete' => self::score($spam['delete'] ?: 150),
                'allow' => \implode("\n", $spam['allow']), 'block' => \implode("\n", $spam['block']),
            ],
            'errors' => [],
            'filtering' => $this->mail->setting('spam', 'off') === 'on',
            'reject' => self::score((int) $this->mail->setting('reject', '150')),
            'max_senders' => Mailboxes::MAX_SENDERS,
            'tabs' => self::userTabs($this->router),
            'tab' => 'spam',
            'save_url' => $this->router->url('user.mail.spam.save'),
        ], title: 'Mail.spam_title', active: 'mail', status: $status);
    }

    public function track(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);

        return $this->trackPage($request, $session, $identity, 'user', (string) $account['username']);
    }

    // -------------------------------------------------------------------------
    // Admin panel

    public function queuePage(Request $request, Session $session, Identity $identity): Response
    {
        $search = \trim((string) $request->query('q', ''));
        [$list, $error] = $this->queue->list($search);

        return $this->pages->render($request, $session, $identity, 'admin', '@Mail/queue', [
            'list' => $list,
            'error' => $error,
            'search' => $search,
            'tabs' => self::adminTabs($this->router),
            'tab' => 'queue',
            'act_url' => $this->router->url('admin.mail.queue.act'),
            'view_base' => \rtrim($this->router->url('admin.mail.queue'), '/'),
        ], title: 'Mail.queue_title', active: 'mail');
    }

    public function queueMessage(Request $request, Session $session, Identity $identity, string $id): Response
    {
        [$message, $error] = $this->queue->view($id);

        if ($message === null && $error === 'Mail.error_queue_none') {
            throw HttpException::notFound();
        }

        return $this->pages->render($request, $session, $identity, 'admin', '@Mail/queue-message', [
            'id' => $id,
            'message' => $message,
            'error' => $error,
            'tabs' => self::adminTabs($this->router),
            'tab' => 'queue',
            'act_url' => $this->router->url('admin.mail.queue.act'),
            'back_url' => $this->router->url('admin.mail.queue'),
        ], title: 'Mail.queue_title', active: 'mail');
    }

    public function queueAct(Request $request, Session $session, Identity $identity): Response
    {
        $ids = $request->input('ids', []);
        $ids = \is_array($ids) ? \array_map(static fn(mixed $v): string => \is_scalar($v) ? (string) $v : '', $ids) : [(string) $ids];
        $action = (string) $request->input('action', '');
        $outcome = $this->actions->queueAction($identity, $request, $action, \array_values($ids));

        match ($outcome->status) {
            MailOutcome::REFUSED => PanelPage::notice($session, 'error', (string) $outcome->message),
            MailOutcome::FAILED => PanelPage::notice($session, 'error', 'Mail.refused'),
            default => PanelPage::notice($session, 'success', 'Mail.queue_done_' . $action),
        };

        // Back to the message unless it is gone.
        $one = \count($ids) === 1 && \in_array($action, ['freeze', 'thaw'], true) && \preg_match(MailActions::MESSAGE_ID, $ids[0]) === 1;

        return new RedirectResponse($one ? $this->router->url('admin.mail.queue.view', ['id' => $ids[0]]) : $this->router->url('admin.mail.queue'), 303);
    }

    public function adminTrack(Request $request, Session $session, Identity $identity): Response
    {
        return $this->trackPage($request, $session, $identity, 'admin', null);
    }

    // -------------------------------------------------------------------------

    private function trackPage(Request $request, Session $session, Identity $identity, string $area, ?string $username): Response
    {
        $search = \trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $days = (int) $request->query('days', '7');
        [$result, $error] = $this->queue->track($username, $search, $status, $days);

        return $this->pages->render($request, $session, $identity, $area, '@Mail/track', [
            'result' => $result,
            'error' => $error,
            'search' => $search,
            'status' => \in_array($status, MailQueue::TRACK_STATUSES, true) ? $status : '',
            'days' => \in_array($days, MailQueue::TRACK_DAYS, true) ? $days : 7,
            'statuses' => MailQueue::TRACK_STATUSES,
            'day_options' => MailQueue::TRACK_DAYS,
            'area' => $area,
            'tabs' => $area === 'admin' ? self::adminTabs($this->router) : self::userTabs($this->router),
            'tab' => 'track',
            'action' => $area === 'admin' ? $this->router->url('admin.mail.track') : $this->router->url('user.mail.track'),
        ], title: 'Mail.track_title', active: 'mail');
    }

    /** A score in tenths as people write it: 60 is "6", 65 is "6.5". */
    public static function score(int $tenths): string
    {
        return $tenths % 10 === 0 ? (string) \intdiv($tenths, 10) : \sprintf('%d.%d', \intdiv($tenths, 10), $tenths % 10);
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity): array
    {
        return $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
    }
}
