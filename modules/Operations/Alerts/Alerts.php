<?php

declare(strict_types=1);

namespace App\Modules\Operations\Alerts;

use App\Engine\Auth\Identity;
use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;
use App\Engine\Http\Request;
use App\Modules\Audit\AuditLog;

/**
 * Raising alerts. Anything that has something to tell calls raise() (an event
 * that happened: a service that fell over, an update that failed) or
 * condition() (something that holds for a while: a full disk, a certificate
 * about to expire). Each alert is recorded, then handed to every enabled
 * channel that wants its category and severity.
 *
 * - The same alert (same key) is not sent twice inside the quiet period; it is
 *   kept in the history as suppressed.
 * - A condition is told when it begins, again if it lasts a day, and when it
 *   ends ("Resolved: ...") at the severity it began with.
 * - Nothing here throws, and a channel that does not answer delays the caller
 *   by eight seconds at most (see Delivery): alerts are raised from the watchdog
 *   and from requests, and must never break them.
 */
final class Alerts
{
    /** How long a condition that holds is left alone before it is told again, in seconds. */
    private const REMIND = 86400;

    /** How long the history is kept, in seconds. */
    private const KEEP = 7776000;

    public function __construct(
        private readonly ConnectionManager $connections,
        private readonly AlertChannels $channels,
        private readonly AlertSettings $settings,
        private readonly Delivery $delivery,
        private readonly AuditLog $audit,
    ) {}

    /**
     * An event: record it and send it.
     *
     * @return int|null the alert's id, null when it could not be recorded
     */
    public function raise(string $category, string $key, string $severity, string $title, string $detail = ''): ?int
    {
        try {
            $key = \mb_substr($key, 0, 120);
            $quiet = $this->settings->get()['quiet'] * 60;
            $recent = $this->table()
                ->where('alert_key', $key)->where('suppressed', 0)
                ->where('at', '>=', \gmdate('Y-m-d H:i:s', \time() - $quiet))
                ->first();
            $now = \gmdate('Y-m-d H:i:s');
            $id = (int) $this->table()->insert([
                'category' => \mb_substr($category, 0, 16),
                'alert_key' => $key,
                'severity' => $severity,
                'title' => \mb_substr($title, 0, 200),
                'detail' => \mb_substr($detail, 0, 1000),
                'suppressed' => $recent === null ? 0 : 1,
                'at' => $now,
            ], 'id');

            if ($recent !== null) {
                return $id;
            }

            $sent = 0;
            $failed = 0;
            $alert = ['category' => $category, 'severity' => $severity, 'title' => $title, 'detail' => $detail, 'at' => $now];

            foreach ($this->channels->receiving($category, $severity) as $channel) {
                [$ok, $why] = $this->delivery->send($channel, $alert);
                $this->record($id, (int) $channel['id'], $ok, $why);
                $ok ? $sent++ : $failed++;
            }

            if ($sent + $failed > 0) {
                $this->table()->where('id', $id)->update(['sent' => $sent, 'failed' => $failed]);
            }

            return $id;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Something that is true or not, looked at again and again: tells when it
     * becomes true, once a day while it stays, and when it stops.
     *
     * @return string|null 'raised', 'reminded', 'resolved', or null when nothing changed
     */
    public function condition(string $category, string $key, bool $firing, string $severity, string $title, string $detail = ''): ?string
    {
        try {
            $active = $this->active()->where('alert_key', $key)->first();
            $now = \gmdate('Y-m-d H:i:s');

            if ($firing && $active === null) {
                $this->active()->insert(['alert_key' => $key, 'since' => $now, 'told_at' => $now]);
                $this->raise($category, $key, $severity, $title, $detail);

                return 'raised';
            }

            if ($firing && \strtotime((string) $active['told_at'] . ' UTC') < \time() - self::REMIND) {
                $this->active()->where('alert_key', $key)->update(['told_at' => $now]);
                $this->raise($category, $key . '.again', $severity, $title, $detail);

                return 'reminded';
            }

            if (!$firing && $active !== null) {
                $this->active()->where('alert_key', $key)->delete();
                $this->raise($category, $key . '.resolved', $severity, 'Resolved: ' . $title, '');

                return 'resolved';
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * The keys of the conditions that hold now.
     *
     * @return list<string>
     */
    public function activeKeys(string $prefix): array
    {
        $out = [];

        foreach ($this->active()->get() as $row) {
            if (\str_starts_with((string) $row['alert_key'], $prefix)) {
                $out[] = (string) $row['alert_key'];
            }
        }

        return $out;
    }

    /**
     * Send a test to one channel, whatever its categories and severity.
     *
     * @return array{0: bool, 1: string} whether it was delivered, and if not why
     */
    public function test(int $channelId, ?Identity $actor, ?Request $request): array
    {
        $channel = $this->channels->open($channelId);

        if ($channel === null) {
            return [false, 'Operations.alerts_error_missing'];
        }

        $alert = ['category' => 'test', 'severity' => 'info', 'title' => 'Test alert from LPanel', 'detail' => 'If you can read this, alerts reach ' . (string) $channel['name'] . '.', 'at' => \gmdate('Y-m-d H:i:s')];
        [$ok, $why] = $this->delivery->send($channel, $alert);
        $this->channels->delivered($channelId, $ok, $why);
        $this->audit->record('alerts.test', $actor, $request, (string) $channel['name'], $ok ? AuditLog::OK : AuditLog::FAILED, $ok ? [] : ['error' => $why]);

        return [$ok, $why];
    }

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function history(int $limit = 50, ?string $category = null): array
    {
        $q = $this->table()->orderByDesc('id')->limit(\max(1, \min(500, $limit)));

        if ($category !== null) {
            $q = $q->where('category', $category);
        }

        return \array_map(static fn(array $r): array => [
            'id' => (int) $r['id'],
            'category' => (string) $r['category'],
            'key' => (string) $r['alert_key'],
            'severity' => (string) $r['severity'],
            'title' => (string) $r['title'],
            'detail' => (string) $r['detail'],
            'suppressed' => (bool) $r['suppressed'],
            'sent' => (int) $r['sent'],
            'failed' => (int) $r['failed'],
            'at' => (string) $r['at'],
        ], $q->get());
    }

    /**
     * What happened to one alert, channel by channel.
     *
     * @return list<array{channel: string, ok: bool, error: string, at: string}>
     */
    public function deliveries(int $alertId): array
    {
        $out = [];
        $names = [];

        foreach ($this->channels->all() as $c) {
            $names[(int) $c['id']] = (string) $c['name'];
        }

        foreach ($this->connections->connection()->table('ops_alert_deliveries')->where('alert_id', $alertId)->orderBy('id')->get() as $r) {
            $out[] = ['channel' => $names[(int) $r['channel_id']] ?? '(removed)', 'ok' => (bool) $r['ok'], 'error' => (string) ($r['error'] ?? ''), 'at' => (string) $r['at']];
        }

        return $out;
    }

    /** Alerts and what became of them from over three months ago go. */
    public function tidy(): int
    {
        $before = \gmdate('Y-m-d H:i:s', \time() - self::KEEP);
        $old = \array_map(static fn(array $r): int => (int) $r['id'], $this->table()->where('at', '<', $before)->get());

        if ($old === []) {
            return 0;
        }

        $this->connections->connection()->table('ops_alert_deliveries')->whereIn('alert_id', $old)->delete();

        return $this->table()->whereIn('id', $old)->delete();
    }

    private function record(int $alertId, int $channelId, bool $ok, string $why): void
    {
        $this->connections->connection()->table('ops_alert_deliveries')->insert([
            'alert_id' => $alertId,
            'channel_id' => $channelId,
            'ok' => $ok ? 1 : 0,
            'error' => $ok ? null : \mb_substr($why, 0, 300),
            'at' => \gmdate('Y-m-d H:i:s'),
        ]);
        $this->channels->delivered($channelId, $ok, $why);
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('ops_alerts');
    }

    private function active(): QueryBuilder
    {
        return $this->connections->connection()->table('ops_alert_active');
    }
}
