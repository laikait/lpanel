<?php

declare(strict_types=1);

namespace App\Modules\Web\Waf;

use App\Engine\Auth\Identity;
use App\Engine\Cli\Output;
use App\Engine\Localization\Localization;
use App\Modules\Identity\PanelUsers;

/** waf:* from the shell, as root: the same settings as the page and the API. */
final class WafCommands
{
    public function __construct(
        private readonly Waf $waf,
        private readonly PanelUsers $panelUsers,
        private readonly Localization $localization,
    ) {}

    /** The firewall's state, its settings and the latest requests the rules disliked. */
    public function status(Output $output): int
    {
        $info = $this->waf->info();
        $s = $this->waf->settings();

        if ($info === null) {
            return $this->fail($output, 'Web.waf_error_unreachable');
        }

        if (!($info['available'] ?? false)) {
            $output->warning($this->localization->get(($info['server'] ?? '') === 'nginx' ? 'Web.waf_nginx' : 'Web.waf_missing'));
        } else {
            $output->line(\sprintf('ModSecurity is loaded, Core Rule Set %s.', ($info['rules'] ?? '') !== '' ? (string) $info['rules'] : '(version unknown)'));
        }

        $output->line(\sprintf('Sites that have not chosen are in "%s" mode. Paranoia level %d.', $s['default'], $s['paranoia']));
        $c = $this->waf->counts();
        $output->line(\sprintf('Requests the rules disliked in the last day: %d, refused: %d.', $c['day'], $c['blocked']));
        $events = $this->waf->recent(limit: 10);

        if ($events !== []) {
            $output->line();
            $output->line(\sprintf('%-20s %-28s %-16s %-5s %s', 'AT (UTC)', 'SITE', 'FROM', 'SCORE', 'RULES'));

            foreach ($events as $e) {
                $output->line(\sprintf('%-20s %-28s %-16s %-5d %s%s', (string) $e['seen_at'], (string) $e['host'], (string) $e['ip'], (int) $e['score'], $e['blocked'] ? 'REFUSED ' : '', \implode(' ', \array_map(static fn(array $r): string => (string) $r['id'], $e['rules']))));
            }
        }

        return 0;
    }

    /** Change the server's settings: --mode off|detect|block for sites that have not chosen, --paranoia 1-4. */
    public function set(Output $output, ?string $mode = null, ?string $paranoia = null): int
    {
        if ($mode === null && $paranoia === null) {
            $output->error('Nothing to change: give --mode or --paranoia.');

            return 1;
        }

        if ($mode !== null) {
            $why = $this->waf->setDefault($this->root(), null, $mode);

            if ($why !== null) {
                return $this->fail($output, $why);
            }
        }

        if ($paranoia !== null) {
            [$ok, $why] = $this->waf->setParanoia($this->root(), null, \ctype_digit($paranoia) ? (int) $paranoia : 0);

            if (!$ok) {
                return $this->fail($output, (string) $why);
            }
        }

        $output->success('Saved.');

        return 0;
    }

    /** Read what the rules said since last time (this runs every few minutes). */
    public function scan(Output $output): int
    {
        $r = $this->waf->scan();

        if ($r['error'] !== null) {
            return $this->fail($output, $r['error']);
        }

        if ($r['events'] > 0) {
            $output->line(\sprintf('%d request(s) the rules disliked.', $r['events']));
        }

        return 0;
    }

    private function fail(Output $output, string $why): int
    {
        $output->error(\str_starts_with($why, 'Web.') ? $this->localization->get($why) : $why);

        return 1;
    }

    private function root(): Identity
    {
        return $this->panelUsers->root()->identity;
    }
}
