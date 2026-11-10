<?php

declare(strict_types=1);

namespace App\Modules\Agent\Commands;

use App\Engine\Cli\Output;
use App\Engine\Config\Config;
use App\Modules\Agent\AgentClient;
use App\Modules\Agent\AgentKey;
use App\Modules\Agent\AgentUnavailable;

/**
 * agent:keygen, agent:ping and agent:facts.
 */
final class AgentCommands
{
    public function __construct(private readonly Config $config) {}

    /**
     * Make the panel's signing key and the public half the agent reads. The
     * installer runs it as root, then gives the secret to the panel's user.
     */
    public function keygen(Output $output, ?string $secret = null, ?string $public = null, bool $force = false): int
    {
        $secret ??= (string) $this->config->get('Agent.key_file');
        $public ??= '/etc/lpa/panel.pub';

        if (!$force && (\is_file($secret) || \is_file($public))) {
            $output->error(\sprintf('%s or %s exists already. Pass --force to replace both; the agent must then be restarted.', $secret, $public));

            return 1;
        }

        foreach ([$secret, $public] as $path) {
            if (!\is_dir(\dirname($path)) && !@\mkdir(\dirname($path), 0o755, true)) {
                $output->error(\sprintf('Cannot create %s.', \dirname($path)));

                return 1;
            }
        }

        $pair = AgentKey::generate();
        $old = \umask(0o077);

        try {
            $written = \file_put_contents($secret, $pair['secret'] . "\n") !== false;
        } finally {
            \umask($old);
        }

        if (!$written || \file_put_contents($public, $pair['public'] . "\n") === false) {
            $output->error('The key files could not be written.');

            return 1;
        }

        @\chmod($secret, 0o600);
        @\chmod($public, 0o644);
        $output->success(\sprintf('Signing key written to %s (keep it 0600, owned by the panel user); public key to %s.', $secret, $public));

        return 0;
    }

    public function ping(Output $output, AgentClient $agent): int
    {
        $started = \hrtime(true);

        try {
            $reply = $agent->call('system.ping', timeout: 5.0);
        } catch (AgentUnavailable $e) {
            $output->error($e->getMessage());

            return 1;
        }

        if (!$reply->done()) {
            $output->error(\sprintf('The agent refused: %s (%s)', $reply->errorCode, $reply->errorMessage));

            return 1;
        }

        $output->success(\sprintf('lpa %s answered in %.1f ms.', (string) ($reply->result['agent'] ?? '?'), (\hrtime(true) - $started) / 1e6));

        return 0;
    }

    public function facts(Output $output, AgentClient $agent): int
    {
        try {
            $reply = $agent->call('system.facts', timeout: 10.0);
        } catch (AgentUnavailable $e) {
            $output->error($e->getMessage());

            return 1;
        }

        if (!$reply->done()) {
            $output->error(\sprintf('The agent refused: %s (%s)', $reply->errorCode, $reply->errorMessage));

            return 1;
        }

        $output->line((string) \json_encode($reply->result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));

        return 0;
    }
}
