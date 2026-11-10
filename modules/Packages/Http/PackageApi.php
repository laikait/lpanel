<?php

declare(strict_types=1);

namespace App\Modules\Packages\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Localization\Localization;
use App\Modules\Audit\AuditLog;
use App\Modules\Packages\Packages;

/**
 * /admin/api/v1/packages: the same rules as the pages, as JSON.
 */
final class PackageApi
{
    public function __construct(
        private readonly Packages $packages,
        private readonly AuditLog $audit,
        private readonly Localization $localization,
    ) {}

    public function index(Identity $identity): JsonResponse
    {
        return ApiResponse::collection(\array_map(Packages::present(...), $this->packages->visibleTo($identity)));
    }

    public function show(Identity $identity, string $id): JsonResponse
    {
        $row = $this->packages->find($identity, $id) ?? throw HttpException::notFound();

        return ApiResponse::item(Packages::present($row));
    }

    public function create(Request $request, Identity $identity): JsonResponse
    {
        $owner = $this->packages->scopeOf($identity);
        [$clean, $errors] = $this->packages->validate($request->input(), $owner);

        if ($errors !== []) {
            return self::invalid($errors, $this->localization);
        }

        $id = $this->packages->create($clean, $owner);
        $this->audit->record('package.create', $identity, $request, $clean['name'], AuditLog::OK, ['id' => $id, 'via' => 'api'] + $clean);

        return ApiResponse::created(Packages::present($this->packages->byId($id) ?? []));
    }

    /**
     * A 422 with each field's message in the panel's language.
     *
     * @param array<string, string> $errors field => lang key
     */
    public static function invalid(array $errors, Localization $localization): JsonResponse
    {
        $messages = [];

        foreach ($errors as $field => $key) {
            $messages[$field] = $localization->get($key, ['min' => 12]);
        }

        return new JsonResponse(['error' => 'invalid', 'errors' => $messages], 422);
    }
}
