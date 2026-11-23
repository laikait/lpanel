<?php

declare(strict_types=1);

namespace App\Modules\Security\Firewall;

use App\Engine\Auth\Identity;
use App\Engine\Http\Request;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentReply;
use App\Modules\Agent\AgentUnavailable;
use App\Modules\Agent\Protocol;
use App\Modules\Audit\AuditLog;

/**
 * The server's firewall (firewalld), as the Admin panel, the API and the
 * command line manage it: ports, the allow and deny lists of addresses, and
 * blocked countries.
 *
 * Every change goes to the agent first and is kept here only once the agent
 * has done it, so the panel never shows a rule the server does not have. The
 * administrator making a change is named to the agent as an address that
 * must stay reachable (`protect`): a change that would shut them out is
 * refused there.
 */
final class Firewall
{
    /** How long a blocked address stays blocked, by what the form offers (seconds; 0 is for good). */
    public const DURATIONS = ['1h' => 3600, '1d' => 86400, '7d' => 604800, '30d' => 2592000, 'never' => 0];

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Entries $entries,
        private readonly AuditLog $audit,
    ) {}

    /**
     * firewalld's state and what the server holds, or null when the agent does not answer.
     *
     * @return array<string, mixed>|null
     */
    public function info(): ?array
    {
        try {
            $reply = $this->agent->call('firewall.info', [], timeout: 30.0);
        } catch (AgentUnavailable) {
            return null;
        }

        return $reply->done() ? $reply->result : null;
    }

    /**
     * Open a port, or limit it to some addresses (none is everyone).
     *
     * @param list<string> $sources
     *
     * @return array{0: bool, 1: string|null} done, and why not (a language key, or the agent's words)
     */
    public function openPort(?Identity $actor, ?Request $request, string $port, string $proto, array $sources): array
    {
        return $this->port($actor, $request, 'open', $port, $proto, $sources);
    }

    /** @return array{0: bool, 1: string|null} */
    public function closePort(?Identity $actor, ?Request $request, string $port, string $proto): array
    {
        return $this->port($actor, $request, 'close', $port, $proto, []);
    }

    /**
     * @param list<string> $sources
     *
     * @return array{0: bool, 1: string|null}
     */
    private function port(?Identity $actor, ?Request $request, string $action, string $port, string $proto, array $sources): array
    {
        $clean = [];

        foreach ($sources as $source) {
            [$norm, $error] = Cidr::normalize($source);

            if ($norm === null) {
                return [false, $error];
            }

            $clean[] = $norm;
        }

        [$ok, $why] = $this->send('firewall.port', ['action' => $action, 'port' => $port, 'proto' => $proto, 'sources' => $clean, 'protect' => $this->protect($request)], 130.0);
        $this->audit->record('firewall.port', $actor, $request, $port . '/' . $proto, $ok ? AuditLog::OK : AuditLog::FAILED, [
            'action' => $action, 'sources' => \implode(', ', $clean),
        ] + ($ok ? [] : ['error' => $why]));

        return [$ok, $why];
    }

    /**
     * Add an address to a list.
     *
     * @param string $duration a key of DURATIONS (deny entries only)
     *
     * @return array{0: bool, 1: string|null} done, and why not
     */
    public function addEntry(?Identity $actor, ?Request $request, string $list, string $address, string $note = '', string $duration = 'never', string $source = 'manual'): array
    {
        if (!\in_array($list, Entries::LISTS, true)) {
            return [false, 'Security.fw_error_list'];
        }

        [$cidr, $error] = Cidr::normalize($address);

        if ($cidr === null) {
            return [false, $error];
        }

        if (!isset(self::DURATIONS[$duration])) {
            return [false, 'Security.fw_error_duration'];
        }

        if ($this->entries->findAddress($list, $cidr) !== null) {
            return [false, 'Security.fw_error_exists'];
        }

        $seconds = $list === Entries::DENY ? self::DURATIONS[$duration] : 0;
        $expires = $seconds > 0 ? \gmdate('Y-m-d H:i:s', \time() + $seconds) : null;

        [$ok, $why] = $this->pushLists($request, $list === Entries::ALLOW ? [[$cidr], []] : [[], [$cidr]], [[], []]);
        $this->audit->record('firewall.' . $list, $actor, $request, $cidr, $ok ? AuditLog::OK : AuditLog::FAILED, [
            'note' => $note, 'expires' => $expires, 'source' => $source,
        ] + ($ok ? [] : ['error' => $why]));

        if ($ok) {
            $this->entries->add($list, $cidr, $note, $source, $actor === null ? null : (int) $actor->id, $expires);
        }

        return [$ok, $why];
    }

    /** @return array{0: bool, 1: string|null} */
    public function removeEntry(?Identity $actor, ?Request $request, int $id): array
    {
        $row = $this->entries->find($id);

        if ($row === null) {
            return [false, 'Security.fw_error_missing'];
        }

        $list = (string) $row['list'];
        [$ok, $why] = $this->pushLists($request, [[], []], $list === Entries::ALLOW ? [[(string) $row['cidr']], []] : [[], [(string) $row['cidr']]]);
        $this->audit->record('firewall.remove', $actor, $request, (string) $row['cidr'], $ok ? AuditLog::OK : AuditLog::FAILED, ['list' => $list] + ($ok ? [] : ['error' => $why]));

        if ($ok) {
            $this->entries->remove($id);
        }

        return [$ok, $why];
    }

    /**
     * Block exactly these countries (their lists are fetched when the server
     * has none; $refresh fetches them again).
     *
     * @param list<string> $codes
     *
     * @return array{0: bool, 1: string|null}
     */
    public function setCountries(?Identity $actor, ?Request $request, array $codes, bool $refresh = false): array
    {
        [$codes, $bad] = Countries::parse($codes);

        if ($bad !== []) {
            return [false, 'Security.fw_error_country'];
        }

        [$ok, $why] = $this->send('firewall.countries', ['codes' => $codes, 'refresh' => $refresh, 'protect' => $this->protect($request)], 620.0);
        $this->audit->record('firewall.countries', $actor, $request, 'countries', $ok ? AuditLog::OK : AuditLog::FAILED, ['codes' => \implode(' ', $codes), 'refresh' => $refresh] + ($ok ? [] : ['error' => $why]));

        if ($ok) {
            $this->entries->setCountries($codes);
            $this->entries->remember('countries_fetched_at', \gmdate('Y-m-d H:i:s'));
        }

        return [$ok, $why];
    }

    /**
     * Take expired deny entries out of the list and tell the server.
     *
     * @return int how many ended
     */
    public function expire(): int
    {
        $expired = $this->entries->expired();

        if ($expired === []) {
            return 0;
        }

        [$ok] = $this->pushLists(null, [[], []], [[], []]);

        if ($ok) {
            foreach ($expired as $row) {
                $this->entries->remove((int) $row['id']);
            }
        }

        return $ok ? \count($expired) : 0;
    }

    /**
     * Send the lists and the countries to the server again, as the panel has
     * them (after the server's firewall was reset, or a restore).
     *
     * @return array{0: bool, 1: string|null}
     */
    public function resend(?Request $request = null): array
    {
        [$ok, $why] = $this->pushLists($request, [[], []], [[], []]);

        if (!$ok) {
            return [false, $why];
        }

        return $this->send('firewall.countries', ['codes' => $this->entries->countries(), 'refresh' => false, 'protect' => $this->protect($request)], 620.0);
    }

    /**
     * Send both lists as they would be after a change.
     *
     * @param array{0: list<string>, 1: list<string>} $add    [allow, deny] addresses to add
     * @param array{0: list<string>, 1: list<string>} $remove [allow, deny] addresses to take away
     *
     * @return array{0: bool, 1: string|null}
     */
    private function pushLists(?Request $request, array $add, array $remove): array
    {
        return $this->send('firewall.lists', [
            'allow' => $this->entries->active(Entries::ALLOW, $remove[0], $add[0]),
            'deny' => $this->entries->active(Entries::DENY, $remove[1], $add[1]),
            'protect' => $this->protect($request),
        ], 200.0);
    }

    /** @return list<string> the administrator's own address, who must stay reachable */
    private function protect(?Request $request): array
    {
        $ip = $request?->ip();

        return $ip !== null && \filter_var($ip, \FILTER_VALIDATE_IP) !== false ? [$ip] : [];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{0: bool, 1: string|null}
     */
    private function send(string $op, array $args, float $timeout): array
    {
        try {
            $reply = $this->agent->call($op, $args, id: Protocol::newId(), timeout: $timeout);
        } catch (AgentUnavailable) {
            return [false, 'Security.fw_error_unreachable'];
        }

        return $reply->done() ? [true, null] : [false, $this->why($reply)];
    }

    private function why(AgentReply $reply): string
    {
        return match ($reply->errorCode) {
            'firewall_off' => 'Security.fw_error_off',
            default => (string) ($reply->errorMessage ?? 'Security.fw_error_failed'),
        };
    }
}
