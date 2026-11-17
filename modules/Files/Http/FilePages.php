<?php

declare(strict_types=1);

namespace App\Modules\Files\Http;

use App\Engine\Auth\Identity;
use App\Engine\Http\HttpException;
use App\Engine\Http\RedirectResponse;
use App\Engine\Http\Request;
use App\Engine\Http\Response;
use App\Engine\Http\StreamResponse;
use App\Engine\Http\UploadedFile;
use App\Engine\Routing\Router;
use App\Engine\Session\Session;
use App\Modules\Accounts\Accounts;
use App\Modules\Files\FileManager;
use App\Modules\Files\FileOutcome;
use App\Modules\Packages\Packages;
use App\Modules\Panel\Ui\PanelPage;
use App\Modules\Subusers\Subusers;

/**
 * User panel → Files: the account's home in a browser.
 *
 * Every page works without JavaScript: the list is one form whose buttons
 * post the ticked paths to each action. While the account is suspended its
 * files can be looked at and downloaded, not changed.
 */
final class FilePages
{
    /** Extensions the editor is not offered for: they are not text. */
    private const BINARY = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'tif', 'tiff', 'psd', 'pdf', 'zip', 'gz', 'tgz', 'tar', 'bz2', 'xz', 'zst', '7z', 'rar', 'mp3', 'mp4', 'm4a', 'mov', 'avi', 'mkv', 'webm', 'ogg', 'wav', 'flac', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'exe', 'dll', 'so', 'bin', 'iso', 'dmg', 'jar', 'class', 'pyc', 'sqlite', 'db', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods'];

    private const IMAGES = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'svg', 'tif', 'tiff'];

    private const CODE = ['php', 'js', 'mjs', 'ts', 'css', 'scss', 'html', 'htm', 'json', 'xml', 'yml', 'yaml', 'py', 'rb', 'pl', 'sh', 'sql', 'ini', 'conf', 'htaccess', 'twig', 'vue', 'jsx', 'tsx', 'md', 'env'];

    public function __construct(
        private readonly FileManager $files,
        private readonly Subusers $subusers,
        private readonly Packages $packages,
        private readonly PanelPage $pages,
        private readonly Router $router,
    ) {}

    // -------------------------------------------------------------------------
    // Pages

    public function index(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $path = $this->path((string) $request->query('path', ''));
        $hidden = (string) $request->query('hidden', '1') !== '0';
        $outcome = $this->files->list($account, $path);

        if (!$outcome->ok() && $path !== '' && \in_array($outcome->code, ['files_missing', 'files_type', 'files_outside'], true)) {
            PanelPage::notice($session, 'error', $outcome->message);

            return $this->back(FileManager::parent($path));
        }

        $entries = [];

        foreach ($outcome->ok() && \is_array($outcome->result['entries'] ?? null) ? $outcome->result['entries'] : [] as $e) {
            if (!\is_array($e) || (!$hidden && \str_starts_with((string) $e['name'], '.'))) {
                continue;
            }

            $entries[] = $this->entry($path, $e);
        }

        return $this->pages->render($request, $session, $identity, 'user', '@Files/files', [
            'account' => $account,
            'path' => $path,
            'shown' => '/' . $path,
            'crumbs' => $this->crumbs($path, 'user.files'),
            'up_url' => $path === '' ? null : $this->url('user.files', FileManager::parent($path), $hidden),
            'entries' => $entries,
            'entry_count' => \count($entries),
            'truncated' => (bool) ($outcome->result['truncated'] ?? false),
            'error' => $outcome->ok() ? null : $outcome->message,
            'hidden' => $hidden,
            'hidden_url' => $this->router->url('user.files', ['path' => $path, 'hidden' => $hidden ? '0' : '1']),
            'readonly' => $account['status'] !== Accounts::ACTIVE,
            'tab' => 'files',
            'tab_urls' => $this->tabUrls($path),
            'urls' => $this->actionUrls(),
            'max_upload' => $this->maxUpload(),
        ], title: 'Files.title', active: 'files');
    }

    public function edit(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $path = $this->path((string) $request->query('path', ''));

        if ($path === '') {
            return $this->back('');
        }

        $outcome = $this->files->read($account, $path);

        if (!$outcome->ok() && !\in_array($outcome->code, ['files_too_big'], true)) {
            PanelPage::notice($session, 'error', $outcome->message);

            return $this->back(FileManager::parent($path));
        }

        return $this->editor($request, $session, $identity, $account, $path, $outcome);
    }

    public function usage(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $path = $this->path((string) $request->query('path', ''));
        $outcome = $this->files->usage($account, $path);

        if (!$outcome->ok() && $path !== '' && \in_array($outcome->code, ['files_missing', 'files_type', 'files_outside'], true)) {
            PanelPage::notice($session, 'error', $outcome->message);

            return new RedirectResponse($this->router->url('user.files.usage', ['path' => FileManager::parent($path)]), 303);
        }

        $total = (int) ($outcome->result['bytes'] ?? 0);
        $rows = [];

        foreach ($outcome->ok() && \is_array($outcome->result['entries'] ?? null) ? $outcome->result['entries'] : [] as $e) {
            if (!\is_array($e)) {
                continue;
            }

            $full = FileManager::join($path, (string) $e['name']);
            $bytes = (int) $e['bytes'];
            $rows[] = [
                'name' => (string) $e['name'],
                'type' => (string) $e['type'],
                'bytes' => $bytes,
                'items' => (int) $e['items'],
                'percent' => $total > 0 ? \round($bytes * 100 / $total, 1) : 0,
                'url' => $e['type'] === 'dir' ? $this->router->url('user.files.usage', ['path' => $full]) : null,
                'browse_url' => $this->router->url('user.files', ['path' => $e['type'] === 'dir' ? $full : $path]),
            ];
        }

        return $this->pages->render($request, $session, $identity, 'user', '@Files/usage', [
            'account' => $account,
            'path' => $path,
            'shown' => '/' . $path,
            'crumbs' => $this->crumbs($path, 'user.files.usage'),
            'rows' => $rows,
            'total' => $total,
            'items' => (int) ($outcome->result['items'] ?? 0),
            'truncated' => (bool) ($outcome->result['truncated'] ?? false),
            'error' => $outcome->ok() ? null : $outcome->message,
            'tab' => 'usage',
            'tab_urls' => $this->tabUrls($path),
            'quota' => $this->quota($account),
        ], title: 'Files.usage_title', active: 'files');
    }

    public function download(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->account($identity);
        $path = $this->path((string) $request->query('path', ''));
        $outcome = $path === '' ? FileOutcome::refused('files_type', 'Files.error_files_type') : $this->files->download($identity, $request, $account, $path);

        if (!$outcome->ok()) {
            PanelPage::notice($session, 'error', $outcome->message);

            return $this->back(FileManager::parent($path));
        }

        return self::send((string) $outcome->result['staged'], FileManager::name($path), (int) ($outcome->result['size'] ?? 0));
    }

    /**
     * A staged download, sent and then removed.
     */
    public static function send(string $file, string $name, int $size): Response
    {
        $ascii = \preg_replace('/[^A-Za-z0-9._ -]+/', '_', $name) ?? 'download';

        return new StreamResponse(static function () use ($file): void {
            try {
                $handle = @\fopen($file, 'rb');

                if ($handle !== false) {
                    \fpassthru($handle);
                    \fclose($handle);
                }
            } finally {
                @\unlink($file);
            }
        }, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $size,
            'Content-Disposition' => 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . \rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ]);
    }

    // -------------------------------------------------------------------------
    // Changes

    public function save(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->writable($identity);
        $path = $this->path((string) $request->input('path', ''));
        $content = (string) $request->input('content', '');
        // Browsers send a textarea's line ends as CRLF; files keep the ones they had.
        if ($request->input('crlf', '0') !== '1') {
            $content = \str_replace("\r\n", "\n", $content);
        }

        $expect = (string) $request->input('expect', '');
        $outcome = $this->files->save($identity, $request, $account, $path, $content, $expect !== '' ? $expect : null);

        if (!$outcome->ok()) {
            if ($outcome->code === 'files_changed') {
                PanelPage::notice($session, 'error', 'Files.error_files_changed');
            }

            return $this->editor($request, $session, $identity, $account, $path, $this->files->read($account, $path), $content, $outcome->message, 422);
        }

        PanelPage::notice($session, 'success', 'Files.saved');

        return new RedirectResponse($this->router->url('user.files.edit', ['path' => $path]), 303);
    }

    public function folder(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->writable($identity);
        $dir = $this->path((string) $request->input('dir', ''));
        $name = \trim((string) $request->input('name', ''));

        return $this->after($session, $dir, $this->files->mkdir($identity, $request, $account, FileManager::join($dir, $name)), 'Files.folder_made');
    }

    public function file(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->writable($identity);
        $dir = $this->path((string) $request->input('dir', ''));
        $path = FileManager::join($dir, \trim((string) $request->input('name', '')));
        $outcome = $this->files->create($identity, $request, $account, $path);

        if (!$outcome->ok()) {
            return $this->after($session, $dir, $outcome, '');
        }

        PanelPage::notice($session, 'success', 'Files.file_made');

        return new RedirectResponse($this->router->url('user.files.edit', ['path' => $path]), 303);
    }

    public function upload(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->writable($identity);
        $dir = $this->path((string) $request->input('dir', ''));
        $overwrite = $request->input('overwrite') === '1';
        $uploads = $request->files()['files'] ?? [];
        $uploads = $uploads instanceof UploadedFile ? [$uploads] : $uploads;
        $done = 0;
        $last = null;

        foreach ($uploads as $file) {
            if (!$file->isValid()) {
                $last = FileOutcome::refused('upload', $file->error() === \UPLOAD_ERR_INI_SIZE || $file->error() === \UPLOAD_ERR_FORM_SIZE ? 'Files.error_upload_size' : 'Files.error_upload');

                continue;
            }

            $outcome = $this->files->upload($identity, $request, $account, $dir, $file->clientName(), $file->temporaryPath(), $overwrite);
            $outcome->ok() ? $done++ : $last = $outcome;
        }

        if ($uploads === []) {
            // A body over post_max_size arrives empty.
            $last = FileOutcome::refused('upload', $request->method() === 'POST' && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0 ? 'Files.error_upload_size' : 'Files.error_no_upload');
        }

        if ($done > 0) {
            PanelPage::notice($session, 'success', $done === 1 ? 'Files.uploaded_one' : 'Files.uploaded');
        }

        if ($last !== null) {
            PanelPage::notice($session, 'error', $last->message);
        }

        return $this->back($dir);
    }

    public function rename(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->writable($identity);
        $dir = $this->path((string) $request->input('dir', ''));
        $paths = $this->paths($request);

        if (\count($paths) !== 1) {
            PanelPage::notice($session, 'error', 'Files.error_rename_one');

            return $this->back($dir);
        }

        $to = FileManager::join(FileManager::parent($paths[0]), \trim((string) $request->input('new_name', '')));

        return $this->after($session, $dir, $this->files->rename($identity, $request, $account, $paths[0], $to), 'Files.renamed');
    }

    public function move(Request $request, Session $session, Identity $identity): Response
    {
        return $this->bulk($request, $session, $identity, 'move');
    }

    public function copy(Request $request, Session $session, Identity $identity): Response
    {
        return $this->bulk($request, $session, $identity, 'copy');
    }

    public function delete(Request $request, Session $session, Identity $identity): Response
    {
        return $this->bulk($request, $session, $identity, 'delete');
    }

    public function chmod(Request $request, Session $session, Identity $identity): Response
    {
        return $this->bulk($request, $session, $identity, 'chmod');
    }

    public function compress(Request $request, Session $session, Identity $identity): Response
    {
        return $this->bulk($request, $session, $identity, 'compress');
    }

    public function extract(Request $request, Session $session, Identity $identity): Response
    {
        $account = $this->writable($identity);
        $dir = $this->path((string) $request->input('dir', ''));
        $path = $this->path((string) $request->input('path', ''));
        $to = $this->destination((string) $request->input('to', ''), $dir);

        if ($to === null) {
            PanelPage::notice($session, 'error', 'Files.error_destination');

            return $this->back($dir);
        }

        $outcome = $this->files->extract($identity, $request, $account, $path, $to);

        if ($outcome->ok() && (int) ($outcome->result['skipped'] ?? 0) > 0) {
            PanelPage::notice($session, 'warning', 'Files.extracted_skipped');

            return $this->back($to);
        }

        return $this->after($session, $outcome->ok() ? $to : $dir, $outcome, 'Files.extracted');
    }

    // -------------------------------------------------------------------------

    private function bulk(Request $request, Session $session, Identity $identity, string $action): Response
    {
        $account = $this->writable($identity);
        $dir = $this->path((string) $request->input('dir', ''));
        $paths = $this->paths($request);

        if ($paths === []) {
            PanelPage::notice($session, 'error', 'Files.error_none_chosen');

            return $this->back($dir);
        }

        if ($action === 'move' || $action === 'copy') {
            $to = $this->destination((string) $request->input($action . '_to', ''), $dir);

            if ($to === null) {
                PanelPage::notice($session, 'error', 'Files.error_destination');

                return $this->back($dir);
            }

            $outcome = $action === 'move'
                ? $this->files->move($identity, $request, $account, $paths, $to)
                : $this->files->copy($identity, $request, $account, $paths, $to);

            return $this->after($session, $dir, $outcome, $action === 'move' ? 'Files.moved' : 'Files.copied');
        }

        return match ($action) {
            'delete' => $this->after($session, $dir, $this->files->delete($identity, $request, $account, $paths), 'Files.deleted'),
            'chmod' => $this->after($session, $dir, $this->files->chmod($identity, $request, $account, $paths, \trim((string) $request->input('mode', ''))), 'Files.permissions_set'),
            default => $this->after($session, $dir, $this->files->compress($identity, $request, $account, $paths, FileManager::join($dir, \trim((string) $request->input('archive', '')))), 'Files.compressed'),
        };
    }

    /**
     * Where something goes: a folder typed as "/public_html/x" (from the
     * home) or "x" (from the folder being looked at).
     */
    private function destination(string $typed, string $dir): ?string
    {
        $typed = \trim($typed);

        if ($typed === '') {
            return $dir;
        }

        return FileManager::clean(\str_starts_with($typed, '/') ? $typed : FileManager::join($dir, $typed));
    }

    private function after(Session $session, string $dir, FileOutcome $outcome, string $done): Response
    {
        PanelPage::notice($session, $outcome->ok() ? 'success' : 'error', $outcome->ok() ? $done : $outcome->message);

        return $this->back($dir);
    }

    /**
     * @param array<string, mixed> $account
     */
    private function editor(Request $request, Session $session, Identity $identity, array $account, string $path, FileOutcome $read, ?string $typed = null, ?string $error = null, int $status = 200): Response
    {
        $r = $read->result;
        $content = $typed ?? (string) ($r['content'] ?? '');

        return $this->pages->render($request, $session, $identity, 'user', '@Files/edit', [
            'account' => $account,
            'path' => $path,
            'name' => FileManager::name($path),
            'crumbs' => $this->crumbs(FileManager::parent($path), 'user.files'),
            'too_big' => $read->code === 'files_too_big',
            'binary' => (bool) ($r['binary'] ?? false),
            'content' => $content,
            'crlf' => \str_contains($content, "\r\n"),
            'size' => (int) ($r['size'] ?? 0),
            'mtime_text' => isset($r['mtime']) ? \gmdate('Y-m-d H:i', (int) $r['mtime']) : '',
            'mode' => (string) ($r['mode'] ?? ''),
            'expect' => (string) ($r['sha256'] ?? ''),
            'error' => $error,
            'readonly' => $account['status'] !== Accounts::ACTIVE,
            'save_url' => $this->router->url('user.files.save'),
            'back_url' => $this->router->url('user.files', ['path' => FileManager::parent($path)]),
            'download_url' => $this->router->url('user.files.download', ['path' => $path]),
            'language' => self::language(FileManager::name($path)),
        ], title: 'Files.edit_title', active: 'files', status: $status);
    }

    /**
     * @param array<string, mixed> $e
     *
     * @return array<string, mixed>
     */
    private function entry(string $dir, array $e): array
    {
        $name = (string) $e['name'];
        $path = FileManager::join($dir, $name);
        $type = (string) $e['type'];
        $isDir = $type === 'dir' || ($type === 'link' && ($e['to'] ?? '') === 'dir');
        $ext = self::extension($name);
        $archive = \preg_match('/\.(zip|tar|tar\.gz|tgz)$/i', $name) === 1;
        $editable = !$isDir && $type !== 'other' && ($e['to'] ?? 'file') !== 'outside' && !\in_array($ext, self::BINARY, true) && (int) $e['size'] <= FileManager::MAX_EDIT;

        return [
            'name' => $name,
            'path' => $path,
            'type' => $type,
            'is_dir' => $isDir,
            'size' => (int) $e['size'],
            'mode' => (string) $e['mode'],
            'mtime' => (int) $e['mtime'],
            'mtime_text' => \gmdate('Y-m-d H:i', (int) $e['mtime']),
            'target' => (string) ($e['target'] ?? ''),
            'outside' => ($e['to'] ?? '') === 'outside',
            'broken' => $type === 'link' && ($e['to'] ?? '') === '',
            'hidden' => \str_starts_with($name, '.'),
            'protected' => $dir === '' && \in_array($name, ['public_html', 'mail', 'tmp', 'logs'], true),
            'icon' => $isDir ? 'folder' : ($type === 'link' ? 'link' : ($archive ? 'archive' : (\in_array($ext, self::IMAGES, true) ? 'image' : (\in_array($ext, self::CODE, true) ? 'code' : 'file')))),
            'archive' => $archive,
            'editable' => $editable,
            'open_url' => $isDir ? $this->router->url('user.files', ['path' => $path]) : ($editable ? $this->router->url('user.files.edit', ['path' => $path]) : $this->router->url('user.files.download', ['path' => $path])),
            'download_url' => $isDir ? null : $this->router->url('user.files.download', ['path' => $path]),
            'edit_url' => $editable ? $this->router->url('user.files.edit', ['path' => $path]) : null,
        ];
    }

    /**
     * @return list<array{name: string, url: string}>
     */
    private function crumbs(string $path, string $route): array
    {
        $crumbs = [['name' => '', 'url' => $this->router->url($route)]];
        $at = '';

        foreach ($path === '' ? [] : \explode('/', $path) as $part) {
            $at = FileManager::join($at, $part);
            $crumbs[] = ['name' => $part, 'url' => $this->router->url($route, ['path' => $at])];
        }

        return $crumbs;
    }

    /** @return array{files: string, usage: string} */
    private function tabUrls(string $path): array
    {
        $at = $path === '' ? [] : ['path' => $path];

        return ['files' => $this->router->url('user.files', $at), 'usage' => $this->router->url('user.files.usage', $at)];
    }

    /** @return array<string, string> */
    private function actionUrls(): array
    {
        $urls = [];

        foreach (['folder', 'file', 'upload', 'rename', 'move', 'copy', 'delete', 'chmod', 'compress', 'extract'] as $action) {
            $urls[$action] = $this->router->url('user.files.' . $action);
        }

        return $urls;
    }

    /** @return list<string> */
    private function paths(Request $request): array
    {
        $out = [];
        $given = $request->input('paths', []);

        foreach (\is_array($given) ? $given : [] as $p) {
            $clean = \is_string($p) ? FileManager::clean($p) : null;

            if ($clean !== null && $clean !== '') {
                $out[] = $clean;
            }
        }

        return \array_values(\array_unique($out));
    }

    private function path(string $given): string
    {
        return FileManager::clean($given) ?? throw HttpException::notFound();
    }

    private function url(string $route, string $path, bool $hidden): string
    {
        return $this->router->url($route, $hidden ? ['path' => $path] : ['path' => $path, 'hidden' => '0']);
    }

    private function back(string $dir): Response
    {
        return new RedirectResponse($this->router->url('user.files', $dir === '' ? [] : ['path' => $dir]), 303);
    }

    /** @return array<string, mixed> */
    private function account(Identity $identity): array
    {
        return $this->subusers->accountOf($identity) ?? throw HttpException::notFound();
    }

    /**
     * The account, for a change: a suspended account's files stay as they are.
     *
     * @return array<string, mixed>
     */
    private function writable(Identity $identity): array
    {
        $account = $this->account($identity);

        if ($account['status'] !== Accounts::ACTIVE) {
            throw HttpException::forbidden('The account is suspended; its files cannot be changed.');
        }

        return $account;
    }

    /**
     * The disk space the account has and uses, as last measured: files in the
     * home, databases, and the package's space for both (0: no limit).
     *
     * @param array<string, mixed> $account
     *
     * @return array{home: int, databases: int, limit: int, percent: ?float}|null
     */
    private function quota(array $account): ?array
    {
        if ($account['disk_used_bytes'] === null) {
            return null;
        }

        $home = (int) $account['disk_used_bytes'];
        $databases = (int) ($account['db_used_bytes'] ?? 0);
        $limit = (int) (($this->packages->byId((int) $account['package_id']) ?? [])['disk_mb'] ?? 0) * 1048576;

        return ['home' => $home, 'databases' => $databases, 'limit' => $limit, 'percent' => $limit > 0 ? \min(100.0, \round(($home + $databases) * 100 / $limit, 1)) : null];
    }

    private function maxUpload(): int
    {
        $limit = static function (string $v): int {
            $v = \trim($v);
            $n = (int) $v;

            return match (\strtolower(\substr($v, -1))) {
                'g' => $n << 30,
                'm' => $n << 20,
                'k' => $n << 10,
                default => $n,
            };
        };

        $sizes = \array_filter([$limit((string) \ini_get('upload_max_filesize')), $limit((string) \ini_get('post_max_size'))]);

        return $sizes === [] ? 0 : \min($sizes);
    }

    private static function extension(string $name): string
    {
        if (\str_starts_with($name, '.') && \substr_count($name, '.') === 1) {
            return \strtolower(\substr($name, 1)); // .htaccess, .env
        }

        $at = \strrpos($name, '.');

        return $at === false ? '' : \strtolower(\substr($name, $at + 1));
    }

    /** A hint for the editor: what kind of text this is. */
    private static function language(string $name): string
    {
        return match (self::extension($name)) {
            'php' => 'PHP',
            'js', 'mjs', 'jsx' => 'JavaScript',
            'ts', 'tsx' => 'TypeScript',
            'css', 'scss' => 'CSS',
            'html', 'htm', 'twig' => 'HTML',
            'json' => 'JSON',
            'xml', 'svg' => 'XML',
            'yml', 'yaml' => 'YAML',
            'md' => 'Markdown',
            'py' => 'Python',
            'sh' => 'Shell',
            'sql' => 'SQL',
            'htaccess' => 'Apache',
            'ini', 'conf', 'env' => 'Config',
            default => 'Text',
        };
    }
}
