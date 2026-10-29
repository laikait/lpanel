<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Engine\Http\UploadedFile;
use App\Tests\Support\TestCase;

final class UploadedFileTest extends TestCase
{
    public function test_a_successful_upload_is_valid(): void
    {
        $file = new UploadedFile('/tmp/php123', 'photo.png', 'image/png', 1024);

        self::assertTrue($file->isValid());
        self::assertSame(\UPLOAD_ERR_OK, $file->error());
        self::assertSame(1024, $file->size());
        self::assertSame('image/png', $file->clientMediaType());
        self::assertSame('/tmp/php123', $file->temporaryPath());
    }

    public function test_a_display_name_has_no_control_or_bidi_override_characters(): void
    {
        $file = new UploadedFile('/tmp/x', "photo\u{202E}gpj.exe\x07", null, 1);

        self::assertSame('photogpj.exe', $file->displayName());
    }

    public function test_a_display_name_is_valid_utf8_and_capped(): void
    {
        self::assertSame('abc', (new UploadedFile('/tmp/x', "abc\xFF", null, 1))->displayName());
        self::assertSame(5, \mb_strlen((new UploadedFile('/tmp/x', \str_repeat('ক', 50) . '.png', null, 1))->displayName(5)));
    }

    public function test_an_upload_with_an_error_is_not_valid(): void
    {
        $file = new UploadedFile('', 'photo.png', null, 0, \UPLOAD_ERR_INI_SIZE);

        self::assertFalse($file->isValid());
        self::assertStringContainsString('upload_max_filesize', $file->errorMessage());
    }

    public function test_every_upload_error_has_a_message(): void
    {
        $errors = [
            \UPLOAD_ERR_OK,
            \UPLOAD_ERR_INI_SIZE,
            \UPLOAD_ERR_FORM_SIZE,
            \UPLOAD_ERR_PARTIAL,
            \UPLOAD_ERR_NO_FILE,
            \UPLOAD_ERR_NO_TMP_DIR,
            \UPLOAD_ERR_CANT_WRITE,
            \UPLOAD_ERR_EXTENSION,
            99,
        ];

        foreach ($errors as $error) {
            $message = (new UploadedFile('/tmp/x', 'f', null, 0, $error))->errorMessage();

            self::assertNotSame('', $message);
        }

        self::assertStringContainsString(
            'unknown',
            (new UploadedFile('/tmp/x', 'f', null, 0, 99))->errorMessage(),
        );
    }

    /**
     * The client controls this string entirely. It must never be able to carry
     * a directory component into a path the application builds.
     */
    public function test_the_client_name_cannot_carry_a_path(): void
    {
        self::assertSame(
            'passwd',
            (new UploadedFile('/tmp/x', '../../etc/passwd', null, 0))->clientName(),
        );

        self::assertSame(
            'evil.php',
            (new UploadedFile('/tmp/x', 'C:\\windows\\evil.php', null, 0))->clientName(),
        );
    }

    public function test_a_null_byte_is_stripped_from_the_client_name(): void
    {
        // The byte that could truncate the name inside a C-level path call is
        // gone; the remaining dots are just part of an ordinary file name.
        self::assertSame(
            'shell.php.jpg',
            (new UploadedFile('/tmp/x', "shell.php\0.jpg", null, 0))->clientName(),
        );
    }

    public function test_a_name_that_is_only_dots_becomes_empty(): void
    {
        self::assertSame('', (new UploadedFile('/tmp/x', '..', null, 0))->clientName());
        self::assertSame('', (new UploadedFile('/tmp/x', '.', null, 0))->clientName());
    }

    public function test_the_client_extension_is_lower_cased(): void
    {
        self::assertSame('png', (new UploadedFile('/tmp/x', 'Photo.PNG', null, 0))->clientExtension());
        self::assertSame('', (new UploadedFile('/tmp/x', 'noextension', null, 0))->clientExtension());
    }

    public function test_an_empty_media_type_becomes_null(): void
    {
        self::assertNull((new UploadedFile('/tmp/x', 'f', null, 0))->clientMediaType());
    }

    public function test_moving_an_invalid_upload_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot move an invalid upload');

        (new UploadedFile('', 'f', null, 0, \UPLOAD_ERR_NO_FILE))->moveTo('/tmp/destination');
    }

    public function test_a_valid_upload_can_be_moved(): void
    {
        $source = \tempnam(\sys_get_temp_dir(), 'upl');
        self::assertIsString($source);
        \file_put_contents($source, 'payload');

        $destination = $source . '.moved';

        (new UploadedFile($source, 'f.txt', 'text/plain', 7))->moveTo($destination);

        self::assertFileDoesNotExist($source);
        self::assertSame('payload', \file_get_contents($destination));

        \unlink($destination);
    }

    // ---- normalisation ----------------------------------------------------

    public function test_a_single_file_entry_becomes_one_object(): void
    {
        $files = UploadedFile::normalizeAll([
            'avatar' => [
                'tmp_name' => '/tmp/php123',
                'name' => 'photo.png',
                'type' => 'image/png',
                'size' => 1024,
                'error' => \UPLOAD_ERR_OK,
            ],
        ]);

        self::assertInstanceOf(UploadedFile::class, $files['avatar']);
    }

    /**
     * PHP pivots multi-file inputs so that every attribute is its own array.
     * Getting this wrong is a classic source of "the second file has the first
     * file's name".
     */
    public function test_a_pivoted_multi_file_entry_is_unpivoted_correctly(): void
    {
        $files = UploadedFile::normalizeAll([
            'documents' => [
                'tmp_name' => ['/tmp/a', '/tmp/b'],
                'name' => ['a.pdf', 'b.pdf'],
                'type' => ['application/pdf', 'text/plain'],
                'size' => [10, 20],
                'error' => [\UPLOAD_ERR_OK, \UPLOAD_ERR_PARTIAL],
            ],
        ]);

        self::assertIsArray($files['documents']);
        self::assertCount(2, $files['documents']);

        [$first, $second] = $files['documents'];

        self::assertSame('a.pdf', $first->clientName());
        self::assertSame('/tmp/a', $first->temporaryPath());
        self::assertSame(10, $first->size());
        self::assertTrue($first->isValid());

        self::assertSame('b.pdf', $second->clientName());
        self::assertSame('/tmp/b', $second->temporaryPath());
        self::assertSame(20, $second->size());
        self::assertFalse($second->isValid());
    }

    public function test_entries_without_a_temporary_name_are_skipped(): void
    {
        self::assertSame([], UploadedFile::normalizeAll(['nonsense' => ['name' => 'x']]));
        self::assertSame([], UploadedFile::normalizeAll(['nonsense' => 'not-an-array']));
    }

    public function test_missing_attributes_degrade_rather_than_fatal(): void
    {
        $files = UploadedFile::normalizeAll(['avatar' => ['tmp_name' => '/tmp/x']]);

        self::assertInstanceOf(UploadedFile::class, $files['avatar']);
        self::assertSame(0, $files['avatar']->size());
        self::assertSame(\UPLOAD_ERR_NO_FILE, $files['avatar']->error());
    }
}
