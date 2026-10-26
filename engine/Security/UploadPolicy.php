<?php

declare(strict_types=1);

namespace App\Engine\Security;

use App\Engine\Http\UploadedFile;
use App\Engine\Storage\Disk;
use App\Engine\Support\Path;

/**
 * What an uploaded file has to be before anything is done with it.
 *
 *     $policy = UploadPolicy::images();
 *     $problems = $policy->check($request->file('avatar'));
 *
 *     if ($problems === []) {
 *         $stored = $policy->store($request->file('avatar'), $directory);
 *     }
 *
 * **Nothing here is automatic**, and that is the one design decision worth
 * defending. A framework cannot know that this endpoint takes avatars and that
 * one takes CSVs, so a global upload policy is either wrong for one of them or
 * so permissive it is not a policy. What the framework can do is make the right
 * check short to write and hard to write incompletely -- which is why check()
 * returns every problem rather than the first, and why there is no "is this
 * file ok" boolean anywhere on it.
 *
 * The checks, in the order they matter:
 *
 * **The extension is on an allowlist.** Never a blocklist. A blocklist has to
 * enumerate .php, .phtml, .php5, .phar, .htaccess, .cgi, .pl and whatever the
 * next server module adds; an allowlist has to enumerate .jpg and .png. One of
 * those lists stops being correct when the world changes.
 *
 * **The name is a name, not a path.** "../../index.php" is a filename as far as
 * a browser is concerned.
 *
 * **There is exactly one extension.** "avatar.php.jpg" passes an extension
 * check and is still executed by an Apache with an old AddHandler line, because
 * that configuration matches on any extension in the name rather than the last.
 *
 * **The contents match the extension.** The client's Content-Type is whatever
 * the client said, so it is evidence of nothing; finfo reads the file's own
 * bytes. A .jpg that is really a PHP script fails here.
 *
 * **The size is within the limit.** Checked against the real file rather than
 * anything the request claimed.
 */
final class UploadPolicy
{
    /** 4 MiB, and an explicit argument away from being anything else. */
    public const DEFAULT_BYTES = 4194304;

    /**
     * Extensions that are dangerous wherever they appear in a name.
     *
     * Not the policy -- the policy is the allowlist. This is the second line
     * for the double-extension case, and the list is short because it only has
     * to cover what a web server executes.
     */
    public const EXECUTABLE = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd', 'jsp', 'asp', 'aspx',
        'htaccess', 'htpasswd',
    ];

    /** 25 megapixels: a 5000x5000 photo passes, a 30000x30000 PNG that inflates to gigabytes does not. */
    public const DEFAULT_PIXELS = 25000000;

    /**
     * @param list<string>               $extensions allowed, lowercase, without dots
     * @param array<string, list<string>> $types     extension => media types its bytes may be
     * @param int                        $maxPixels  when above 0, a file whose type is image/* must also
     *                                               decode as an image no larger than this many pixels
     */
    public function __construct(
        private readonly array $extensions,
        private readonly int $maxBytes = self::DEFAULT_BYTES,
        private readonly array $types = [],
        private readonly int $maxPixels = 0,
    ) {}

    /** The common case, with the type mapping already right. */
    public static function images(int $maxBytes = self::DEFAULT_BYTES, int $maxPixels = self::DEFAULT_PIXELS): self
    {
        return new self(['jpg', 'jpeg', 'png', 'gif', 'webp'], $maxBytes, [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
        ], $maxPixels);
    }

    public static function documents(int $maxBytes = self::DEFAULT_BYTES): self
    {
        return new self(['pdf', 'csv', 'txt'], $maxBytes, [
            'pdf' => ['application/pdf'],
            // A CSV is a text file, and finfo says so; it has no type of its
            // own that can be relied on across platforms.
            'csv' => ['text/plain', 'text/csv', 'application/csv'],
            'txt' => ['text/plain'],
        ]);
    }

    /** @return list<string> */
    public function extensions(): array
    {
        return $this->extensions;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /**
     * Everything wrong with this file, in the order somebody should fix it.
     *
     * All the problems, not the first one. A user who fixes the size and is
     * then told about the type has been made to upload twice for one answer
     * the server already had.
     *
     * @return list<string> empty means acceptable
     */
    public function check(?UploadedFile $file): array
    {
        if ($file === null) {
            return ['No file was uploaded.'];
        }

        if (!$file->isValid()) {
            return [$file->errorMessage()];
        }

        $problems = [];
        $name = $file->clientName();

        // Note what is NOT checked here: that the name carries no directory
        // component. UploadedFile::clientName() has already stripped one --
        // null byte first, then Windows separators, then basename -- so a check
        // at this layer could never fire, and a check that can never fire is
        // worse than none: it reads as though this class were the thing
        // standing between "../../etc/passwd" and the filesystem. A name that
        // was nothing but a path arrives here empty and fails the extension
        // check below, which is the honest place for it to fail.
        $extension = $file->clientExtension();

        if ($extension === '' || !\in_array($extension, $this->extensions, true)) {
            $problems[] = \sprintf(
                'A .%s file is not accepted here. Allowed: %s.',
                $extension === '' ? '(none)' : $extension,
                \implode(', ', $this->extensions),
            );
        }

        if ($this->hasExecutableExtension($name)) {
            $problems[] = 'The file name contains an executable extension. '
                . '"avatar.php.jpg" is a .jpg to this check and a script to some web servers.';
        }

        if ($file->size() > $this->maxBytes) {
            $problems[] = \sprintf(
                'The file is %s; the limit is %s.',
                RequestLimits::format($file->size()),
                RequestLimits::format($this->maxBytes),
            );
        }

        $mismatch = $this->contentMismatch($file, $extension);

        if ($mismatch !== null) {
            $problems[] = $mismatch;
        } elseif ($this->maxPixels > 0 && $problems === []) {
            $problem = $this->imageProblem($file, $extension);

            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * check() for every file of a multi-file field, and a limit on how many.
     *
     * @param list<UploadedFile> $files
     *
     * @return list<string> empty means every file is acceptable
     */
    public function checkAll(array $files, int $maxFiles = 10): array
    {
        if (\count($files) > $maxFiles) {
            return [\sprintf('%d files were uploaded; the limit is %d.', \count($files), $maxFiles)];
        }

        $problems = [];

        foreach ($files as $file) {
            foreach ($this->check($file) as $problem) {
                $problems[] = \sprintf('%s: %s', $file->displayName(), $problem);
            }
        }

        return $problems;
    }

    /** A type that says image/* must decode as one, and not be larger than maxPixels. */
    private function imageProblem(UploadedFile $file, string $extension): ?string
    {
        $expected = $this->types[$extension] ?? [];

        if ($expected === [] || !\str_starts_with($expected[0], 'image/')) {
            return null;
        }

        $size = @\getimagesize($file->temporaryPath());

        if ($size === false) {
            return \sprintf('The file is named .%s but is not an image that can be read.', $extension);
        }

        if ($size[0] * $size[1] > $this->maxPixels) {
            return \sprintf(
                'The image is %dx%d pixels; the limit is %s pixels.',
                $size[0],
                $size[1],
                \number_format($this->maxPixels),
            );
        }

        return null;
    }

    /**
     * Move the file under a name this application chose.
     *
     * The stored name is generated, never the client's. A client name is
     * attacker-controlled text that becomes a path, and every rule for making
     * one safe is a rule that can be got subtly wrong; generating one removes
     * the question. The original is the caller's to keep in a database column
     * beside the row, where it is data rather than a filename.
     *
     * @return string the path written to
     */
    public function store(UploadedFile $file, string $directory, ?string $name = null): string
    {
        if (!\is_dir($directory) && !@\mkdir($directory, 0o775, true) && !\is_dir($directory)) {
            throw SecurityException::unwritableDestination($directory);
        }

        $this->require($file);

        $stored = $this->storedName($file, $name);

        $destination = Path::join($directory, $stored);

        $file->moveTo($destination);

        return $destination;
    }

    /**
     * Put the file on a disk -- local, S3, memory -- under a name this
     * application chose, as store() does.
     *
     *     $path = UploadPolicy::images()->storeOn($file, $storage->disk('uploads'), 'avatars');
     *     // "avatars/3f9c…e1.jpg", relative to the disk
     *
     * Streamed from PHP's temporary file, so a large upload is not read into
     * memory first. The temporary file is left for PHP to delete at the end of
     * the request, as it does with any upload not moved.
     *
     * @param string $directory a path on the disk, "" for its root
     *
     * @return string the file's path on the disk
     *
     * @throws \App\Engine\Storage\StorageException
     */
    public function storeOn(UploadedFile $file, Disk $disk, string $directory = '', ?string $name = null): string
    {
        $this->require($file);

        $stored = $this->storedName($file, $name);
        $path = \trim($directory, '/') === '' ? $stored : \trim($directory, '/') . '/' . $stored;

        $stream = @\fopen($file->temporaryPath(), 'rb');

        if ($stream === false) {
            throw SecurityException::unwritableDestination($path);
        }

        try {
            $disk->put($path, $stream);
        } finally {
            \fclose($stream);
        }

        return $path;
    }

    /**
     * store() and storeOn() check the file themselves, so that skipping
     * check() cannot put an unchecked file on disk.
     *
     * @throws SecurityException
     */
    private function require(UploadedFile $file): void
    {
        $problems = $this->check($file);

        if ($problems !== []) {
            throw SecurityException::rejectedUpload($problems);
        }
    }

    private function storedName(UploadedFile $file, ?string $name): string
    {
        $extension = $file->clientExtension();
        $stored = ($name ?? \bin2hex(\random_bytes(16)))
            . (\in_array($extension, $this->extensions, true) ? '.' . $extension : '');

        // Belt and braces on the one part a caller supplies. The generated name
        // is hex and cannot be anything else; a name passed in could be "../x",
        // and this is the check that it is a single path segment rather than a
        // path. Path::within() is the wrong tool here on purpose -- it resolves
        // with realpath() and so is only ever true for a file that already
        // exists, which a destination never is.
        if ($stored !== \basename(\str_replace('\\', '/', $stored)) || \str_contains($stored, '..')) {
            throw SecurityException::unwritableDestination($stored);
        }


        return $stored;
    }

    private function hasExecutableExtension(string $name): bool
    {
        foreach (\explode('.', \strtolower($name)) as $index => $part) {
            if ($index > 0 && \in_array($part, self::EXECUTABLE, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the bytes say, against what the name claims.
     *
     * Fails closed. When a type is declared for the extension, the file is
     * refused if fileinfo is unavailable, and refused if fileinfo cannot say
     * what it is: "could not check" is never "checked". `security:check`
     * reports a missing fileinfo before the first upload does.
     */
    private function contentMismatch(UploadedFile $file, string $extension): ?string
    {
        $expected = $this->types[$extension] ?? null;

        if ($expected === null) {
            return null;
        }

        // A type is declared for this extension, so the content has to be
        // read: without fileinfo the answer is no, not "nothing to say".
        $finfo = \function_exists('finfo_open') ? @\finfo_open(\FILEINFO_MIME_TYPE) : false;

        if ($finfo === false) {
            return \sprintf('The contents of a .%s file cannot be checked: PHP\'s fileinfo extension is not available.', $extension);
        }

        $detected = @\finfo_file($finfo, $file->temporaryPath());
        @\finfo_close($finfo);

        // No answer is not a pass. finfo returning false -- an unreadable
        // temporary file, a broken magic database -- used to wave the file
        // through, which is the one outcome a content check must not have.
        if (!\is_string($detected)) {
            return \sprintf('The contents of this .%s file could not be identified.', $extension);
        }

        if (\in_array($detected, $expected, true)) {
            return null;
        }

        return \sprintf(
            'The file is named .%s but its contents are %s. The name is not evidence of anything.',
            $extension,
            $detected,
        );
    }
}
