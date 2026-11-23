<?php

declare(strict_types=1);

namespace App\Modules\Security\Isolation;

use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;
use App\Modules\Security\Firewall\Entries;

/**
 * Account isolation on the system the accounts share: /proc hides other
 * users' processes (hidepid), and the kernel's protections of links and of
 * files in sticky folders such as /tmp are on.
 *
 * Both are on by default (the installer applies them), and the Admin panel,
 * the API and the command line can turn them off. What was chosen is kept
 * here and sent to the agent whole; it is kept only once the agent has done
 * it, so the panel never claims a protection the server does not have.
 */
final class Isolation
{
    public const DEFAULTS = ['hidepid' => true, 'links' => true];

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Entries $entries,
        private readonly AuditLog $audit,
    ) {}

    /** @return array{hidepid: bool, links: bool} what was chosen (on unless somebody turned it off) */
    public function settings(): array
    {
        return [
            'hidepid' => ($this->entries->setting('iso_hidepid') ?? '1') === '1',
            'links' => ($this->entries->setting('iso_links') ?? '1') === '1',
        ];
    }

    /**
     * What the system has now, or null when the agent does not answer.
     *
     * @return array<string, mixed>|null
     */
    public function info(): ?array
    {
        try {
            $reply = $this->agent->call('isolation.info', [], timeout: 30.0);
        } catch (AgentUnavailable) {
            return null;
        }

        return $reply->done() ? $reply->result : null;
    }

    /**
     * Change the settings (a missing one stays as it is) and make the server
     * match.
     *
     * @param array<string, mixed> $changes hidepid and links, as booleans
     *
     * @return array{0: bool, 1: string|null} done, and why not (a language key, or the agent's words)
     */
    public function configure(?Identity $actor, ?Request $request, array $changes): array
    {
        $now = $this->settings();

        foreach (['hidepid', 'links'] as $name) {
            if (\array_key_exists($name, $changes) && $changes[$name] !== null) {
                $now[$name] = \filter_var($changes[$name], \FILTER_VALIDATE_BOOLEAN);
            }
        }

        [$ok, $why] = $this->send($now);
        $this->audit->record('isolation.settings', $actor, $request, 'isolation', $ok ? AuditLog::OK : AuditLog::FAILED, $now + ($ok ? [] : ['error' => $why]));

        if ($ok) {
            $this->entries->remember('iso_hidepid', $now['hidepid'] ? '1' : '0');
            $this->entries->remember('iso_links', $now['links'] ? '1' : '0');
        }

        return [$ok, $why];
    }

    /**
     * Send the settings again, as the panel has them (a server made again, a restore).
     *
     * @return array{0: bool, 1: string|null}
     */
    public function sync(?Identity $actor = null, ?Request $request = null): array
    {
        [$ok, $why] = $this->send($this->settings());
        $this->audit->record('isolation.sync', $actor, $request, 'isolation', $ok ? AuditLog::OK : AuditLog::FAILED, $this->settings() + ($ok ? [] : ['error' => $why]));

        return [$ok, $why];
    }

    /**
     * @param array{hidepid: bool, links: bool} $settings
     *
     * @return array{0: bool, 1: string|null}
     */
    private function send(array $settings): array
    {
        try {
            $reply = $this->agent->call('isolation.apply', $settings, id: Protocol::newId(), timeout: 130.0);
        } catch (AgentUnavailable) {
            return [false, 'Security.iso_error_unreachable'];
        }

        return $reply->done() ? [true, null] : [false, (string) ($reply->errorMessage ?? 'Security.iso_error_failed')];
    }
}
