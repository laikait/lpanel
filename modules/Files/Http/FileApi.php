<?php

declare(strict_types=1);

namespace App\Modules\Files\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\ApiResponse;
use App\Engine\Http\HttpException;
use App\Engine\Http\JsonResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Http\UploadedFile;
use App\Engine\Localization\Localization;
use App\Modules\Accounts\Accounts;
use App\Modules\Files\FileManager;
use App\Modules\Files\FileOutcome;

/**
 * An account's files through the API, with the same rules as the User panel.
 *
 *   GET  …/files?path=            a folder                  GET  …/files/usage?path=   what takes the space
 *   GET  …/files/content?path=    a file's text             PUT  …/files/content       save {path, content, expect?, create?}
 *   GET  …/files/download?path=   a file, as it is          POST …/files/upload        multipart: dir, file, overwrite
 *   POST …/files/mkdir {path}     POST …/files/create {path}         POST …/files/rename {from, to}
 *   POST …/files/move {paths, to} POST …/files/copy {paths, to}      POST …/files/delete {paths}
 *   POST …/files/chmod {paths, mode}   POST …/files/compress {paths, to}   POST …/files/extract {path, to}
 *
 * Paths are relative to the home. 404 not there, 409 already there or
 * changed since, 403 not allowed, 413 too big, 422 invalid, 507 out of space.
 */
final class FileApi
{
    public function __construct(
        private readonly FileManager $files,
        private readonly Accounts $accounts,
        private readonly Localization $localization,
    ) {}

    public function index(Request $request, Identity $identity, string $username): Response
    {
        return $this->respond($this->files->list($this->account($identity, $username), $this->path($request->query('path', ''))));
    }

    public function usage(Request $request, Identity $identity, string $username): Response
    {
        return $this->respond($this->files->usage($this->account($identity, $username), $this->path($request->query('path', ''))));
    }

    public function content(Request $request, Identity $identity, string $username): Response
    {
        return $this->respond($this->files->read($this->account($identity, $username), $this->path($request->query('path', ''))));
    }

    public function save(Request $request, Identity $identity, string $username): Response
    {
        $account = $this->account($identity, $username);
        $content = $request->input('content');

        if (!\is_string($content)) {
            return $this->invalid('content', 'Files.error_content');
        }

        $expect = (string) $request->input('expect', '');

        return $this->respond($this->files->save($identity, $request, $account, $this->path($request->input('path', '')), $content, $expect !== '' ? $expect : null, $request->input('create') === true));
    }

    public function download(Request $request, Identity $identity, string $username): Response
    {
        $path = $this->path($request->query('path', ''));
        $outcome = $this->files->download($identity, $request, $this->account($identity, $username), $path);

        return $outcome->ok()
            ? FilePages::send((string) $outcome->result['staged'], FileManager::name($path), (int) ($outcome->result['size'] ?? 0))
            : $this->respond($outcome);
    }

    public function upload(Request $request, Identity $identity, string $username): Response
    {
        $account = $this->account($identity, $username);
        $file = $request->file('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->invalid('file', $file !== null && \in_array($file->error(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true) ? 'Files.error_upload_size' : 'Files.error_no_upload');
        }

        $name = (string) $request->input('name', $file->clientName());

        return $this->respond($this->files->upload($identity, $request, $account, $this->path($request->input('dir', '')), $name, $file->temporaryPath(), \in_array($request->input('overwrite'), ['1', 'true', true], true)), 201);
    }

    public function mkdir(Request $request, Identity $identity, string $username): Response
    {
        return $this->respond($this->files->mkdir($identity, $request, $this->account($identity, $username), $this->path($request->input('path', ''))), 201);
    }

    public function create(Request $request, Identity $identity, string $username): Response
    {
        return $this->respond($this->files->create($identity, $request, $this->account($identity, $username), $this->path($request->input('path', ''))), 201);
    }

    public function rename(Request $request, Identity $identity, string $username): Response
    {
        return $this->respond($this->files->rename($identity, $request, $this->account($identity, $username), $this->path($request->input('from', '')), $this->path($request->input('to', ''))));
    }

    public function move(Request $request, Identity $identity, string $username): Response
    {
        return $this->respond($this->files->move($identity, $request, $this->account($identity, $username), $this->paths($request), $this->path($request->input('to', ''))));
    }

    public function copy(Request $request, Identity $identity, string $username): Response
    {
        return $this->respond($this->files->copy($identity, $request, $this->account($identity, $username), $this->paths($request), $this->path($request->input('to', ''))));
    }

    public function delete(Request $request, Identity $identity, string $username): Response
    {
        return $this->respond($this->files->delete($identity, $request, $this->account($identity, $username), $this->paths($request)));
    }

    public function chmod(Request $request, Identity $identity, string $username): Response
    {
        return $this->respond($this->files->chmod($identity, $request, $this->account($identity, $username), $this->paths($request), (string) $request->input('mode', '')));
    }

    public function compress(Request $request, Identity $identity, string $username): Response
    {
        return $this->respond($this->files->compress($identity, $request, $this->account($identity, $username), $this->paths($request), $this->path($request->input('to', ''))), 201);
    }

    public function extract(Request $request, Identity $identity, string $username): Response
    {
        return $this->respond($this->files->extract($identity, $request, $this->account($identity, $username), $this->path($request->input('path', '')), $this->path($request->input('to', ''))));
    }

    // -------------------------------------------------------------------------

    private function respond(FileOutcome $outcome, int $done = 200): Response
    {
        if ($outcome->ok()) {
            return ApiResponse::item($outcome->result, $done);
        }

        $status = match ($outcome->code) {
            'files_missing' => 404,
            'files_exists', 'files_changed', 'files_into', 'not_ready', 'busy' => 409,
            'files_denied', 'files_protected', 'files_outside' => 403,
            'files_too_big' => 413,
            'files_quota' => 507,
            'unreachable', 'staging' => 503,
            'timeout' => 504,
            'input', 'files_type', 'files_binary', 'files_archive', 'upload' => 422,
            default => 502,
        };

        $body = ['error' => $outcome->code, 'message' => $this->localization->get($outcome->message)];

        if ($outcome->detail !== '') {
            $body['detail'] = $outcome->detail;
        }

        return new JsonResponse($body, $status);
    }

    private function invalid(string $field, string $message): JsonResponse
    {
        return new JsonResponse(['error' => 'input', 'message' => $this->localization->get($message), 'field' => $field], 422);
    }

    private function path(mixed $given): string
    {
        $clean = \is_string($given) ? FileManager::clean($given) : null;

        if ($clean === null) {
            throw HttpException::badRequest('A path is not a path within the home.');
        }

        return $clean;
    }

    /** @return list<string> */
    private function paths(Request $request): array
    {
        $given = $request->input('paths', []);

        if (!\is_array($given)) {
            throw HttpException::badRequest('paths is a list of paths.');
        }

        return \array_values(\array_map(fn(mixed $p): string => $this->path($p), $given));
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity, string $username): array
    {
        return $this->accounts->findByUsername($identity, $username) ?? throw HttpException::notFound();
    }
}
