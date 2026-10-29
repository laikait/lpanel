<?php

declare(strict_types=1);

namespace App\Modules\Shared\Storage;

use App\Engine\Http\HttpException;
use App\Engine\Http\Request;
use App\Engine\Http\StreamResponse;
use App\Engine\Storage\Storage;
use App\Engine\Storage\StorageException;

/**
 * GET /files/{disk}?path=…: where a local disk's temporaryUrl() points.
 *
 * The route is signed (meta signed), so by the time this runs the Guard has
 * already refused a link that was changed (403) or has expired (410): the
 * path and disk are ones this application put in a link itself.
 *
 * Streamed, so a large file is not read into memory. Shown in the browser
 * only for types that cannot run script; anything else downloads.
 */
final class FileDownload
{
    /** What a browser may display rather than download; see Storage::INLINE_TYPES. */
    private const INLINE = Storage::INLINE_TYPES;

    public function __invoke(Request $request, Storage $storage, string $disk): StreamResponse
    {
        $path = $request->query('path');

        if (!\is_string($path) || $path === '') {
            throw HttpException::notFound();
        }

        try {
            $files = $storage->disk($disk);
            $stream = $files->readStream($path);
            $size = $files->size($path);
        } catch (StorageException) {
            throw HttpException::notFound();
        }

        $head = (string) \fread($stream, 8192);
        $type = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($head);
        $type = \is_string($type) && $type !== '' ? $type : 'application/octet-stream';
        $name = \basename($path);

        return new StreamResponse(static function () use ($stream, $head): void {
            echo $head;
            \fpassthru($stream);
            \fclose($stream);
        }, 200, [
            'Content-Type' => \in_array($type, self::INLINE, true) ? $type : 'application/octet-stream',
            'Content-Length' => (string) $size,
            'Content-Disposition' => (\in_array($type, self::INLINE, true) ? 'inline' : 'attachment')
                . '; filename="' . \addcslashes((string) \preg_replace('/[^\x20-\x7E]/', '_', $name), '"\\') . '"'
                . "; filename*=UTF-8''" . \rawurlencode($name),
            // The link is the credential: no shared cache may keep the file.
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
