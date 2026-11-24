<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Operations\Cron\CronJobs;

/**
 * An account's cron jobs through the API (the accounts.modify right).
 *
 *   GET    /admin/api/v1/accounts/{username}/cron          the jobs
 *   POST   /admin/api/v1/accounts/{username}/cron          {schedule, command}
 *   DELETE /admin/api/v1/accounts/{username}/cron/{id}
 *
 *   200 listed   201 made   204 removed   404 no such account or job   422 invalid   502 the server refused
 */
final class CronApi
{
    public function __construct(
        private readonly CronJobs $jobs,
        private readonly Accounts $accounts,
        private readonly Localization $localization,
    ) {}

    public function index(Identity $identity, string $username): JsonResponse
    {
        $account = $this->account($identity, $username);

        return ApiResponse::item(['jobs' => \array_map(self::present(...), $this->jobs->forAccount((int) $account['id']))]);
    }

    public function add(Request $request, Identity $identity, string $username): JsonResponse
    {
        $account = $this->account($identity, $username);
        [$id, $errors] = $this->jobs->add($identity, $request, $account, (string) $request->input('schedule', ''), (string) $request->input('command', ''));

        if ($id === null) {
            if (isset($errors['server'])) {
                return new JsonResponse(['error' => 'refused', 'message' => $this->text($errors['server'])], 502);
            }

            return new JsonResponse(['error' => 'invalid', 'errors' => \array_map($this->text(...), $errors)], 422);
        }

        foreach ($this->jobs->forAccount((int) $account['id']) as $job) {
            if ($job['id'] === $id) {
                return ApiResponse::created(self::present($job));
            }
        }

        return ApiResponse::created(['id' => $id]);
    }

    public function remove(Request $request, Identity $identity, string $username, string $id): Response|JsonResponse
    {
        $account = $this->account($identity, $username);
        [$ok, $why] = $this->jobs->remove($identity, $request, $account, (int) $id);

        if (!$ok) {
            return $why === 'Operations.cron_error_missing' ? throw HttpException::notFound() : new JsonResponse(['error' => 'refused', 'message' => $this->text((string) $why)], 502);
        }

        return ApiResponse::noContent();
    }

    /**
     * @param array<string, mixed> $job
     *
     * @return array<string, mixed>
     */
    private static function present(array $job): array
    {
        return ['id' => (int) $job['id'], 'schedule' => (string) $job['schedule'], 'command' => (string) $job['command'], 'created_at' => $job['created_at']];
    }

    private function text(string $key): string
    {
        return \str_starts_with($key, 'Operations.') ? $this->localization->get($key) : $key;
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity, string $username): array
    {
        return $this->accounts->findByUsername($identity, $username) ?? throw HttpException::notFound();
    }
}
