<?php

declare(strict_types=1);

namespace App\Engine\Http;

/**
 * One entry from $_FILES, normalised.
 *
 * The client-supplied name is never trusted for anything but display: moveTo()
 * requires a destination the application chose. clientName() is sanitised so it
 * cannot carry a path, but it is still attacker-controlled text.
 */
final class UploadedFile
{
    public function __construct(
        private readonly string $temporaryPath,
        private readonly string $clientName,
        private readonly ?string $clientMediaType,
        private readonly int $size,
        private readonly int $error = \UPLOAD_ERR_OK,
    ) {}

    public function isValid(): bool
    {
        return $this->error === \UPLOAD_ERR_OK && $this->temporaryPath !== '';
    }

    public function error(): int
    {
        return $this->error;
    }

    public function errorMessage(): string
    {
        return match ($this->error) {
            \UPLOAD_ERR_OK => 'The file uploaded successfully.',
            \UPLOAD_ERR_INI_SIZE => 'The file exceeds the upload_max_filesize directive.',
            \UPLOAD_ERR_FORM_SIZE => 'The file exceeds the form MAX_FILE_SIZE directive.',
            \UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
            \UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            \UPLOAD_ERR_NO_TMP_DIR => 'The temporary upload directory is missing.',
            \UPLOAD_ERR_CANT_WRITE => 'The file could not be written to disk.',
            \UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
            default => 'The upload failed for an unknown reason.',
        };
    }

    public function size(): int
    {
        return $this->size;
    }

    public function temporaryPath(): string
    {
        return $this->temporaryPath;
    }

    /**
     * The original file name with any directory component removed.
     *
     * Order matters. The null byte goes first, because it can truncate a name
     * inside a C-level path call. Windows separators are normalised next, so a
     * client on Windows cannot smuggle a path past basename(). Only then is the
     * last segment taken: stripping separators before basename() would turn
     * "../../etc/passwd" into "....etcpasswd" instead of "passwd".
     */
    public function clientName(): string
    {
        $name = \str_replace("\0", '', $this->clientName);
        $name = \basename(\str_replace('\\', '/', $name));

        return $name === '.' || $name === '..' ? '' : $name;
    }

    /**
     * clientName() made fit to show: valid UTF-8, no control or bidirectional
     * override characters, at most $maxLength characters.
     *
     * "photo\u{202E}gpj.exe" is displayed as "photoexe.jpg" by a browser that
     * honours the override, so a name shown to a person or put in a
     * Content-Disposition header goes through here first. It is still only for
     * display: nothing is ever stored under it.
     */
    public function displayName(int $maxLength = 120): string
    {
        // The /u flag makes preg_replace() return null on invalid UTF-8, so
        // the bytes that are not UTF-8 are dropped first.
        $name = (string) \iconv('UTF-8', 'UTF-8//IGNORE', $this->clientName());
        $name = (string) \preg_replace('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', $name);

        return \mb_substr($name, 0, \max(1, $maxLength));
    }

    public function clientExtension(): string
    {
        return \strtolower(\pathinfo($this->clientName(), \PATHINFO_EXTENSION));
    }

    /** The media type the client claimed. Never trust it; sniff instead. */
    public function clientMediaType(): ?string
    {
        return $this->clientMediaType;
    }

    public function moveTo(string $destination): void
    {
        if (!$this->isValid()) {
            throw new \RuntimeException(\sprintf('Cannot move an invalid upload: %s', $this->errorMessage()));
        }

        // is_uploaded_file() is false for anything this process created itself,
        // which is exactly the case in tests, so fall back to a plain rename.
        $moved = \is_uploaded_file($this->temporaryPath)
            ? \move_uploaded_file($this->temporaryPath, $destination)
            : \rename($this->temporaryPath, $destination);

        if ($moved === false) {
            throw new \RuntimeException(\sprintf('Could not move the uploaded file to "%s".', $destination));
        }
    }

    /**
     * Normalise a raw $_FILES array.
     *
     * PHP pivots multi-file inputs so that every attribute becomes its own
     * array; this turns them back into one object per file.
     *
     * @param array<string, mixed> $files
     *
     * @return array<string, UploadedFile|list<UploadedFile>>
     */
    public static function normalizeAll(array $files): array
    {
        $normalized = [];

        foreach ($files as $field => $entry) {
            if (!\is_array($entry) || !\array_key_exists('tmp_name', $entry)) {
                continue;
            }

            $normalized[$field] = \is_array($entry['tmp_name'])
                ? self::normalizeMany($entry)
                : self::normalizeOne($entry);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $entry
     *
     * @return list<UploadedFile>
     */
    private static function normalizeMany(array $entry): array
    {
        $files = [];

        /** @var array<array-key, mixed> $names */
        $names = \is_array($entry['tmp_name']) ? $entry['tmp_name'] : [];

        foreach (\array_keys($names) as $index) {
            $files[] = self::normalizeOne([
                'tmp_name' => self::pluck($entry, 'tmp_name', $index),
                'name' => self::pluck($entry, 'name', $index),
                'type' => self::pluck($entry, 'type', $index),
                'size' => self::pluck($entry, 'size', $index),
                'error' => self::pluck($entry, 'error', $index),
            ]);
        }

        return $files;
    }

    /** @param array<string, mixed> $entry */
    private static function pluck(array $entry, string $key, int|string $index): mixed
    {
        $values = $entry[$key] ?? null;

        return \is_array($values) ? ($values[$index] ?? null) : null;
    }

    /** @param array<string, mixed> $entry */
    private static function normalizeOne(array $entry): self
    {
        $temporaryPath = $entry['tmp_name'] ?? null;
        $clientName = $entry['name'] ?? null;
        $type = $entry['type'] ?? null;
        $size = $entry['size'] ?? null;
        $error = $entry['error'] ?? null;

        return new self(
            \is_string($temporaryPath) ? $temporaryPath : '',
            \is_string($clientName) ? $clientName : '',
            \is_string($type) && $type !== '' ? $type : null,
            \is_numeric($size) ? (int) $size : 0,
            \is_numeric($error) ? (int) $error : \UPLOAD_ERR_NO_FILE,
        );
    }
}
