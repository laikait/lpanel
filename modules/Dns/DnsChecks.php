<?php

declare(strict_types=1);

namespace App\Modules\Dns;

use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentUnavailable;

/**
 * What the world's DNS says, asked through the agent (dns.lookup): whether
 * a domain is delegated to the nameservers its zone names, and whether a
 * nameserver's own name leads to this server (its glue is in place).
 */
final class DnsChecks
{
    public function __construct(private readonly AgentClient $agent) {}

    /**
     * @param list<string> $zones       zone name => expected nameservers
     * @param list<string> $nameservers
     *
     * @return array{known: bool, zones: array<string, list<string>>, hosts: array<string, array{addresses: list<string>, here: bool}>, running: ?bool, version: string}
     */
    public function lookup(array $zones, array $nameservers): array
    {
        $out = ['known' => false, 'zones' => [], 'hosts' => [], 'running' => null, 'version' => ''];

        try {
            $info = $this->agent->call('dns.info', [], timeout: 10.0);

            if ($info->done()) {
                $out['running'] = (bool) ($info->result['running'] ?? false);
                $out['version'] = (string) ($info->result['version'] ?? '');
            }

            if ($zones === [] && $nameservers === []) {
                return $out;
            }

            $reply = $this->agent->call('dns.lookup', ['hosts' => \array_slice(\array_values($nameservers), 0, 20), 'zones' => \array_slice(\array_values($zones), 0, 20)], timeout: 20.0);
        } catch (AgentUnavailable) {
            return $out;
        }

        if (!$reply->done()) {
            return $out;
        }

        $out['known'] = true;

        foreach ((array) ($reply->result['zones'] ?? []) as $zone => $list) {
            $out['zones'][(string) $zone] = \array_values(\array_map('strval', (array) $list));
        }

        foreach ((array) ($reply->result['hosts'] ?? []) as $host => $answer) {
            $out['hosts'][(string) $host] = [
                'addresses' => \array_values(\array_map('strval', (array) ($answer['addresses'] ?? []))),
                'here' => (bool) ($answer['here'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * How a zone's delegation looks: "ok" (the registry names this zone's
     * nameservers), "elsewhere" (it names others), "none" (it names none:
     * not registered, or not delegated yet) or "unknown".
     *
     * @param list<string> $expected the zone's nameservers
     * @param list<string>|null $found what the registry says
     */
    public static function delegation(array $expected, ?array $found): string
    {
        if ($found === null) {
            return 'unknown';
        }

        if ($found === []) {
            return 'none';
        }

        return \array_intersect($found, $expected) !== [] ? 'ok' : 'elsewhere';
    }
}
