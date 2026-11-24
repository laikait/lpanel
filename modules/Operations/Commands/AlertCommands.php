<?php

declare(strict_types=1);

namespace App\Modules\Operations\Commands;

use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Identity\PanelUsers;
use App\Modules\Operations\Alerts\AlertChannels;
use App\Modules\Operations\Alerts\AlertChecks;
use App\Modules\Operations\Alerts\Alerts;
use App\Modules\Operations\Alerts\AlertSettings;

/** alerts:* from the shell, as root: the same as the Alerts page and the API. */
final class AlertCommands
{
    public function __construct(
        private readonly AlertChannels $channels,
        private readonly Alerts $alerts,
        private readonly AlertChecks $checks,
        private readonly AlertSettings $settings,
        private readonly PanelUsers $panelUsers,
        private readonly Localization $localization,
    ) {}

    /** The channels, the thresholds and the latest alerts. */
    public function status(Output $output, int $limit = 15): int
    {
        $output->line(\sprintf('%-4s %-22s %-9s %-26s %-9s %s', 'ID', 'CHANNEL', 'KIND', 'TARGET', 'FROM', 'LAST'));

        foreach ($this->channels->all() as $c) {
            $output->line(\sprintf(
                '%-4d %-22s %-9s %-26s %-9s %s',
                $c['id'],
                \mb_substr($c['name'], 0, 22),
                $c['kind'],
                \mb_substr($c['target'], 0, 26),
                $c['enabled'] ? $c['min_severity'] : 'off',
                $c['last_error'] !== null ? 'failed: ' . $c['last_error'] : ($c['last_ok_at'] === null ? 'nothing sent yet' : 'ok ' . $c['last_ok_at']),
            ));
        }

        $s = $this->settings->get();
        $output->line('');
        $output->line(\sprintf('speaks up   disk %s, memory %s, load %s, certificates %s; the same alert at most every %d minutes', self::off($s['disk'], '%'), self::off($s['memory'], '%'), self::off($s['load'], ' per CPU'), self::off($s['ssl'], ' days'), $s['quiet']));
        $output->line('');
        $output->line(\sprintf('%-5s %-20s %-9s %-10s %s', 'ID', 'AT (UTC)', 'SEVERITY', 'SENT', 'WHAT'));

        foreach ($this->alerts->history($limit) as $a) {
            $output->line(\sprintf('%-5d %-20s %-9s %-10s %s', $a['id'], $a['at'], $a['severity'], $a['suppressed'] ? 'repeat' : $a['sent'] . ($a['failed'] > 0 ? '+' . $a['failed'] . ' failed' : ''), $a['title']));
        }

        return 0;
    }

    /** Add a channel: email, telegram, slack or webhook. */
    public function add(Output $output, string $kind, string $name, ?string $to = null, ?string $token = null, ?string $chat = null, ?string $url = null, ?string $key = null, ?string $categories = null, ?string $from = null): int
    {
        $input = \array_filter(['kind' => $kind, 'name' => $name, 'to' => $to, 'token' => $token, 'chat' => $chat, 'url' => $url, 'signing_key' => $key, 'categories' => $categories, 'min_severity' => $from], static fn(?string $v): bool => $v !== null);
        [$c, $errors] = $this->channels->validate($input);

        if ($errors !== []) {
            foreach ($errors as $k => $key) {
                $output->error($k . ': ' . $this->localization->get($key));
            }

            return 1;
        }

        $id = $this->channels->create($c, $this->root(), null);
        $output->success('Channel ' . $id . ' added (alerts:test ' . $id . ' sends it a test).');

        return 0;
    }

    /** Remove a channel. */
    public function remove(Output $output, string $id): int
    {
        if (!$this->channels->remove((int) $id, $this->root(), null)) {
            $output->error($this->localization->get('Operations.alerts_error_missing'));

            return 1;
        }

        $output->success('Channel removed.');

        return 0;
    }

    /** Send a test alert to a channel. */
    public function test(Output $output, string $id): int
    {
        [$ok, $why] = $this->alerts->test((int) $id, $this->root(), null);

        if (!$ok) {
            $output->error(\str_starts_with($why, 'Operations.') ? $this->localization->get($why) : 'The test did not arrive: ' . $why);

            return 1;
        }

        $output->success('The test alert was delivered.');

        return 0;
    }

    /** Show or change when the server speaks up. */
    public function setSettings(Output $output, ?string $disk = null, ?string $memory = null, ?string $load = null, ?string $ssl = null, ?string $quiet = null): int
    {
        $input = \array_filter(['disk' => $disk, 'memory' => $memory, 'load' => $load, 'ssl' => $ssl, 'quiet' => $quiet], static fn(?string $v): bool => $v !== null);
        [$s, $errors] = $this->settings->validate($input);

        if ($errors !== []) {
            foreach ($errors as $key) {
                $output->error($this->localization->get($key));
            }

            return 1;
        }

        if ($input !== []) {
            $this->settings->save($s, $this->root(), null);
        }

        $output->line('disk          ' . self::off($s['disk'], '% used'));
        $output->line('memory        ' . self::off($s['memory'], '% in use'));
        $output->line('load          ' . self::off($s['load'], ' per CPU (five minutes)'));
        $output->line('certificates  ' . self::off($s['ssl'], ' days before they expire'));
        $output->line('quiet         ' . $s['quiet'] . ' minutes between the same alert');

        return 0;
    }

    /** Look at the server once (every five minutes). */
    public function check(Output $output): int
    {
        $r = $this->checks->run();
        $this->alerts->tidy();

        if ($r['raised'] > 0 || $r['resolved'] > 0) {
            $output->line(\sprintf('%d began, %d ended.', $r['raised'], $r['resolved']));
        }

        return 0;
    }

    private static function off(int $v, string $unit): string
    {
        return $v === 0 ? 'off' : $v . $unit;
    }

    private function root(): \App\Engine\Auth\Identity
    {
        return $this->panelUsers->root()->identity;
    }
}
