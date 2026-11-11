<?php

declare(strict_types=1);

namespace App\Modules\Web\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Web\Bandwidth;
use App\Modules\Web\PhpVersions;
use App\Modules\Web\WebSync;

/**
 * The server's PHP versions, and each account's bandwidth, in the API.
 *
 *   GET    /admin/api/v1/php                       every version, its extensions and sites
 *   PUT    /admin/api/v1/php/default               {version}
 *   POST   /admin/api/v1/php/{version}             install it (202 and the job)
 *   DELETE /admin/api/v1/php/{version}             remove it (202 and the job)
 *   PUT    /admin/api/v1/php/{version}/extensions  {extensions: [...]} the whole set (202 and the job)
 *   GET    /admin/api/v1/php/jobs/{id}             a job: running, done or failed
 *   GET    /admin/api/v1/accounts/{username}/bandwidth
 *
 *   409 not now (another job runs, sites use it, the panel needs it)
 *   422 not something the panel offers     503 the agent cannot be asked
 */
final class PhpVersionApi
{
    private const CONFLICT = ['Web.php_busy', 'Web.php_in_use', 'Web.php_panel', 'Web.php_is_default', 'Web.php_locked', 'Web.php_already', 'Web.php_not_installed', 'Web.php_no_change'];

    public function __construct(
        private readonly PhpVersions $php,
        private readonly Accounts $accounts,
        private readonly Bandwidth $bandwidth,
        private readonly Localization $localization,
    ) {}

    public function index(): JsonResponse
    {
        $this->php->settleAll();
        $info = $this->php->info() ?? throw new HttpException(503, $this->localization->get('Web.php_agent_down'));
        $sites = $this->php->sitesByVersion();
        $default = $this->php->defaultVersion();

        return ApiResponse::item([
            'default' => WebSync::phpLabel($default),
            'versions' => \array_map(static fn(array $v): array => [
                'version' => WebSync::phpLabel((string) $v['version']),
                'installed' => (bool) $v['installed'],
                'extensions' => $v['extensions'],
                'locked' => $v['locked'],
                'sites' => $sites[(string) $v['version']] ?? 0,
                'running' => (bool) $v['running'],
                'panel' => (bool) $v['panel'],
                'default' => $v['version'] === $default,
                'end_of_life' => \in_array($v['version'], PhpVersions::END_OF_LIFE, true),
            ], $info['versions']),
            'extensions' => $info['extensions'],
            'new_version_extensions' => $info['defaults'],
            'job' => self::presentJob($this->php->running()),
        ]);
    }

    public function install(Request $request, Identity $identity, string $version): JsonResponse
    {
        return $this->started($this->php->install($identity, $request, self::digits($version)));
    }

    public function remove(Request $request, Identity $identity, string $version): JsonResponse
    {
        return $this->started($this->php->remove($identity, $request, self::digits($version)));
    }

    public function extensions(Request $request, Identity $identity, string $version): JsonResponse
    {
        $wanted = $request->input('extensions');

        if (!\is_array($wanted)) {
            return new JsonResponse(['error' => 'invalid', 'message' => $this->localization->get('Web.php_unknown_extension')], 422);
        }

        return $this->started($this->php->extensions($identity, $request, self::digits($version), \array_values(\array_filter($wanted, 'is_string'))));
    }

    public function setDefault(Request $request, Identity $identity): JsonResponse
    {
        $why = $this->php->setDefault($identity, $request, self::digits((string) $request->input('version', '')));

        return $why === null ? ApiResponse::item(['default' => WebSync::phpLabel($this->php->defaultVersion())]) : $this->refuse($why);
    }

    public function job(string $id): JsonResponse
    {
        $this->php->settleAll();

        return ApiResponse::item(self::presentJob($this->php->job((int) $id) ?? throw HttpException::notFound()));
    }

    public function bandwidth(Identity $identity, string $username): JsonResponse
    {
        $account = $this->accounts->findByUsername($identity, $username) ?? throw HttpException::notFound();

        return ApiResponse::item($this->bandwidth->usage($account) + ['days' => $this->bandwidth->daily((int) $account['id'])]);
    }

    /** @param array{0: ?array<string, mixed>, 1: ?string} $result */
    private function started(array $result): JsonResponse
    {
        [$job, $why] = $result;

        if ($job === null) {
            return $this->refuse((string) $why);
        }

        return $job['state'] === PhpVersions::FAILED
            ? new JsonResponse(['error' => 'failed', 'message' => (string) $job['error'], 'data' => self::presentJob($job)], 502)
            : ApiResponse::accepted(self::presentJob($job));
    }

    private function refuse(string $why): JsonResponse
    {
        $status = match (true) {
            $why === 'Web.php_agent_down' => 503,
            \in_array($why, self::CONFLICT, true) => 409,
            default => 422,
        };

        return new JsonResponse(['error' => \substr($why, 8), 'message' => $this->localization->get($why)], $status);
    }

    /**
     * @param array<string, mixed>|null $job
     *
     * @return array<string, mixed>|null
     */
    private static function presentJob(?array $job): ?array
    {
        return $job === null ? null : [
            'id' => (int) $job['id'],
            'op' => $job['op'],
            'version' => WebSync::phpLabel((string) $job['version']),
            'state' => $job['state'],
            'error' => $job['error'],
            'by' => $job['actor_name'],
            'created_at' => $job['created_at'],
            'finished_at' => $job['finished_at'],
        ];
    }

    /** "8.4" or "84" → "84" */
    private static function digits(string $version): string
    {
        return \str_replace('.', '', $version);
    }
}
