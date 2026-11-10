<?php

declare(strict_types=1);

namespace App\Modules\Resellers\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Audit\AuditLog;
use App\Modules\Packages\Http\PackageApi;
use App\Modules\Resellers\Resellers;

/**
 * /admin/api/v1/resellers, for root and administrators.
 */
final class ResellerApi
{
    public function __construct(
        private readonly Resellers $resellers,
        private readonly ResellerPages $pages,
        private readonly AuditLog $audit,
        private readonly Localization $localization,
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::collection(\array_map(self::present(...), $this->resellers->all()));
    }

    public function show(string $username): JsonResponse
    {
        return ApiResponse::item(self::present($this->reseller($username)));
    }

    public function create(Request $request, Identity $identity): JsonResponse
    {
        [$clean, $errors] = $this->pages->validateNew($request->input());

        if ($errors !== []) {
            return PackageApi::invalid($errors, $this->localization);
        }

        $id = $this->resellers->create($clean['username'], $clean['name'], $clean['email'], $clean['password'], $clean['limits']);
        $this->audit->record('reseller.create', $identity, $request, $clean['username'], AuditLog::OK, ['id' => $id, 'via' => 'api'] + $clean['limits']);

        return ApiResponse::created(self::present($this->resellers->find($id) ?? []));
    }

    public function limits(Request $request, Identity $identity, string $username): JsonResponse
    {
        $reseller = $this->reseller($username);
        // Fields left out keep their value.
        [$clean, $errors] = $this->resellers->validateLimits($request->input() + $reseller['limits']);

        if ($errors !== []) {
            return PackageApi::invalid($errors, $this->localization);
        }

        $this->resellers->saveLimits((int) $reseller['id'], $clean);
        $this->audit->record('reseller.limits', $identity, $request, (string) $reseller['username'], AuditLog::OK, ['via' => 'api'] + $clean);

        return ApiResponse::item(self::present($this->resellers->find((int) $reseller['id']) ?? []));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function present(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'name' => (string) $row['name'],
            'email' => $row['email'],
            'active' => (bool) $row['active'],
            'limits' => $row['limits'],
            'usage' => $row['usage'],
        ];
    }

    /** @return array<string, mixed> */
    private function reseller(string $username): array
    {
        return $this->resellers->findByUsername($username) ?? throw HttpException::notFound();
    }
}
