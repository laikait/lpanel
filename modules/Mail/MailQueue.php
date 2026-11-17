<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;

/**
 * Reading Exim's queue and log through the agent: the queue (Admin panel),
 * one queued message, and delivery tracking (both panels; an account sees
 * only mail from or to its own domains). What changes the queue is in
 * MailActions.
 *
 * Each read returns [result, error]: the agent's result, or why there is
 * none ("unreachable", or the agent's message).
 */
final class MailQueue
{
    public const TRACK_STATUSES = ['delivered', 'deferred', 'failed', 'rejected', 'pending'];
    public const TRACK_DAYS = [1, 3, 7, 14, 30];

    public function __construct(private readonly AgentClient $agent) {}

    /** @return array{0: ?array<string, mixed>, 1: ?string} the queue, oldest first */
    public function list(string $search = '', int $limit = 500): array
    {
        return $this->read('mail.queue', ['search' => \mb_substr(\trim($search), 0, 254), 'limit' => $limit]);
    }

    /** @return array{0: ?array<string, mixed>, 1: ?string} one queued message and its log */
    public function view(string $id): array
    {
        if (\preg_match(MailActions::MESSAGE_ID, $id) !== 1) {
            return [null, 'Mail.error_queue_none'];
        }

        return $this->read('mail.queue_view', ['id' => $id]);
    }

    /**
     * Deliveries of the last days, newest first; an account's own when
     * $username is given.
     *
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    public function track(?string $username, string $search = '', string $status = '', int $days = 7, int $limit = 500): array
    {
        if (!\in_array($status, self::TRACK_STATUSES, true)) {
            $status = '';
        }

        if (!\in_array($days, self::TRACK_DAYS, true)) {
            $days = 7;
        }

        return $this->read('mail.track', ['username' => $username ?? '', 'search' => \mb_substr(\trim($search), 0, 254), 'status' => $status, 'days' => $days, 'limit' => $limit]);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private function read(string $op, array $args): array
    {
        try {
            $reply = $this->agent->call($op, $args, timeout: 60.0);
        } catch (AgentUnavailable) {
            return [null, 'Mail.error_unreachable'];
        }

        if ($reply->errorCode === 'mail_no_message') {
            return [null, 'Mail.error_queue_none'];
        }

        if (!$reply->done()) {
            return [null, (string) ($reply->errorMessage ?? 'The agent gave no reason.')];
        }

        return [$reply->result, null];
    }
}
