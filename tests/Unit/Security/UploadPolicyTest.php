<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Engine\Http\UploadedFile;
use App\Engine\Security\UploadPolicy;
use App\Tests\Support\TestCase;

/**
 * The checks an upload has to pass, and why each of them is not the obvious one.
 */
final class UploadPolicyTest extends TestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (\is_file($path)) {
                @\unlink($path);
            }
        }

        $this->temporary = [];

        parent::tearDown();
    }

    private function upload(string $clientName, string $contents = 'x', ?string $type = null): UploadedFile
    {
        $path = \sys_get_temp_dir() . '/upload-' . \bin2hex(\random_bytes(6));
        \file_put_contents($path, $contents);
        $this->temporary[] = $path;

        return new UploadedFile(
            temporaryPath: $path,
            clientName: $clientName,
            clientMediaType: $type,
            size: \strlen($contents),
            error: \UPLOAD_ERR_OK,
        );
    }

    private function directory(): string
    {
        $path = \sys_get_temp_dir() . '/uploads-' . \bin2hex(\random_bytes(6));
        $this->temporary[] = $path;

        return $path;
    }

    // ---- the allowlist ---------------------------------------------------------

    public function test_store_refuses_a_file_that_fails_the_check(): void
    {
        $this->expectException(\App\Engine\Security\SecurityException::class);

        (new UploadPolicy(['txt']))->store($this->upload('shell.php'), $this->directory());
    }

    public function test_an_image_that_does_not_decode_is_refused(): void
    {
        // Right magic bytes for finfo, nothing getimagesize() can read.
        $problems = UploadPolicy::images()->check($this->upload('a.gif', 'GIF89a' . \str_repeat("\0", 4)));

        self::assertNotSame([], $problems);
    }

    public function test_an_image_over_the_pixel_limit_is_refused(): void
    {
        $gif = (string) \base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);

        self::assertSame([], UploadPolicy::images()->check($this->upload('p.gif', $gif)));

        $problems = (new UploadPolicy(['gif'], types: ['gif' => ['image/gif']], maxPixels: 1))->check($this->upload('p.gif', $gif));
        self::assertSame([], $problems);

        // 1x1 = 1 pixel is within 1; a stricter policy cannot be below 1, so use a 2x1 image via GD if present.
        if (\function_exists('imagecreatetruecolor')) {
            $image = \imagecreatetruecolor(4, 4);
            \ob_start();
            \imagegif($image);
            $big = (string) \ob_get_clean();

            $problems = (new UploadPolicy(['gif'], types: ['gif' => ['image/gif']], maxPixels: 10))->check($this->upload('big.gif', $big));
            self::assertStringContainsString('4x4 pixels', $problems[0] ?? '');
        }
    }

    public function test_check_all_limits_the_count_and_names_the_file(): void
    {
        $policy = new UploadPolicy(['txt']);

        self::assertSame(['3 files were uploaded; the limit is 2.'], $policy->checkAll([
            $this->upload('a.txt'),
            $this->upload('b.txt'),
            $this->upload('c.txt'),
        ], 2));

        $problems = $policy->checkAll([$this->upload('a.txt'), $this->upload('b.php')]);
        self::assertStringStartsWith('b.php: ', $problems[0]);
    }

    public function test_an_allowed_extension_passes(): void
    {
        self::assertSame([], (new UploadPolicy(['txt']))->check($this->upload('notes.txt')));
    }

    /**
     * An allowlist, never a blocklist. A blocklist has to enumerate every
     * extension a web server might execute -- and stops being correct when the
     * next server module is installed.
     */
    public function test_an_extension_not_on_the_list_is_refused_and_says_what_is(): void
    {
        $problems = (new UploadPolicy(['jpg', 'png']))->check($this->upload('shell.php'));

        self::assertCount(2, $problems, 'the extension, and the executable name');
        self::assertStringContainsString('jpg, png', $problems[0]);
    }

    public function test_a_file_with_no_extension_is_refused(): void
    {
        $problems = (new UploadPolicy(['txt']))->check($this->upload('README'));

        self::assertNotSame([], $problems);
        self::assertStringContainsString('(none)', $problems[0]);
    }

    // ---- the name --------------------------------------------------------------

    /**
     * "../../index.php" is a filename as far as a browser is concerned -- and
     * it is UploadedFile, not this class, that strips the directory component.
     * The policy only ever sees a name that is already only a name.
     *
     * Asserted rather than assumed, because that guarantee is precisely what
     * lets check() not repeat the work. If it ever stopped holding, this fails
     * here instead of the hole opening quietly one layer up.
     */
    public function test_a_name_containing_a_path_arrives_here_already_stripped(): void
    {
        self::assertSame('notes.txt', $this->upload('../../notes.txt')->clientName());
        self::assertSame('notes.txt', $this->upload('C:\\Users\\me\\notes.txt')->clientName());
        self::assertSame([], (new UploadPolicy(['txt']))->check($this->upload('../../notes.txt')));
    }

    /** A name that was nothing but a path has no name left, and fails as one. */
    public function test_a_name_that_is_only_a_path_is_refused(): void
    {
        $problems = (new UploadPolicy(['txt']))->check($this->upload('../..'));

        self::assertNotSame([], $problems);
        self::assertStringContainsString('(none)', $problems[0]);
    }

    /**
     * The check people forget. "avatar.php.jpg" is a .jpg to an extension
     * check and a script to an Apache with an old AddHandler line, because
     * that configuration matches any extension in the name rather than the
     * last one.
     */
    public function test_a_double_extension_is_refused_even_when_the_last_one_is_allowed(): void
    {
        $problems = (new UploadPolicy(['jpg']))->check($this->upload('avatar.php.jpg'));

        self::assertCount(1, $problems);
        self::assertStringContainsString('executable extension', $problems[0]);
    }

    public function test_a_harmless_dot_in_the_name_is_fine(): void
    {
        self::assertSame([], (new UploadPolicy(['txt']))->check($this->upload('meeting.notes.2026.txt')));
    }

    // ---- size ------------------------------------------------------------------

    public function test_a_file_over_the_limit_is_refused_with_both_figures(): void
    {
        $problems = (new UploadPolicy(['txt'], maxBytes: 4))->check($this->upload('notes.txt', 'more than four'));

        self::assertCount(1, $problems);
        self::assertStringContainsString('4 bytes', $problems[0]);
    }

    // ---- contents --------------------------------------------------------------

    /**
     * The client's Content-Type is whatever the client said, so it is evidence
     * of nothing. finfo reads the file's own bytes.
     */
    public function test_a_file_whose_contents_contradict_its_name_is_refused(): void
    {
        if (!\function_exists('finfo_open')) {
            self::markTestSkipped('fileinfo is not installed, so contents cannot be checked.');
        }

        $problems = UploadPolicy::images()->check(
            $this->upload('avatar.png', '<?php echo "hello";', 'image/png'),
        );

        self::assertNotSame([], $problems);
        self::assertStringContainsString('not evidence of anything', \implode(' ', $problems));
    }

    /**
     * finfo with no answer -- here, a temporary file gone before it could be
     * read -- is a refusal. It used to be a pass: "could not check" counted as
     * "checked and fine".
     */
    public function test_contents_that_cannot_be_identified_are_refused(): void
    {
        if (!\function_exists('finfo_open')) {
            self::markTestSkipped('fileinfo is not installed.');
        }

        $file = $this->upload('notes.pdf', '%PDF-1.4');
        @\unlink($file->temporaryPath());

        $problems = UploadPolicy::documents()->check($file);

        self::assertStringContainsString('could not be identified', \implode(' ', $problems));
    }

    public function test_a_real_image_passes(): void
    {
        if (!\function_exists('finfo_open')) {
            self::markTestSkipped('fileinfo is not installed.');
        }

        // The smallest valid GIF there is.
        $gif = \base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
        self::assertIsString($gif);

        self::assertSame([], UploadPolicy::images()->check($this->upload('pixel.gif', $gif)));
    }

    // ---- everything at once ----------------------------------------------------

    /**
     * All the problems, not the first. A user who fixes the size and is then
     * told about the type has uploaded twice for one answer the server already
     * had.
     */
    public function test_every_problem_is_reported_at_once(): void
    {
        $problems = (new UploadPolicy(['jpg'], maxBytes: 2))->check(
            $this->upload('../evil.php.exe', 'far too long for two bytes'),
        );

        self::assertGreaterThanOrEqual(3, \count($problems), 'extension, executable, size');
    }

    public function test_no_file_is_a_problem_rather_than_a_pass(): void
    {
        self::assertSame(['No file was uploaded.'], (new UploadPolicy(['txt']))->check(null));
    }

    public function test_a_failed_upload_reports_php_s_own_reason(): void
    {
        $file = new UploadedFile(
            temporaryPath: '',
            clientName: 'notes.txt',
            clientMediaType: null,
            size: 0,
            error: \UPLOAD_ERR_INI_SIZE,
        );

        $problems = (new UploadPolicy(['txt']))->check($file);

        self::assertCount(1, $problems);
        self::assertNotSame('', $problems[0]);
    }

    // ---- storing ---------------------------------------------------------------

    /**
     * The stored name is generated, never the client's. A client name is
     * attacker-controlled text that becomes a path, and every rule for making
     * one safe is a rule that can be got subtly wrong.
     */
    public function test_the_stored_name_is_not_the_client_s_name(): void
    {
        $directory = $this->directory();
        $stored = (new UploadPolicy(['txt']))->store($this->upload('notes.txt', 'hello'), $directory);

        $this->temporary[] = $stored;

        self::assertFileExists($stored);
        self::assertStringNotContainsString('notes', \basename($stored));
        self::assertStringEndsWith('.txt', $stored);
        self::assertSame('hello', \file_get_contents($stored));
    }

    public function test_the_directory_is_created_if_it_is_missing(): void
    {
        $directory = $this->directory();

        self::assertDirectoryDoesNotExist($directory);

        $stored = (new UploadPolicy(['txt']))->store($this->upload('notes.txt'), $directory);
        $this->temporary[] = $stored;

        self::assertDirectoryExists($directory);
    }

    /** Belt and braces: even a name this application chose is checked. */
    public function test_a_name_that_would_escape_the_directory_is_refused(): void
    {
        $this->expectException(\App\Engine\Security\SecurityException::class);

        (new UploadPolicy(['txt']))->store($this->upload('notes.txt'), $this->directory(), '../escape');
    }

    // ---- the ready-made policies -----------------------------------------------

    public function test_the_image_policy_allows_what_it_should(): void
    {
        self::assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp'], UploadPolicy::images()->extensions());
    }

    public function test_the_document_policy_allows_what_it_should(): void
    {
        self::assertSame(['pdf', 'csv', 'txt'], UploadPolicy::documents()->extensions());
    }
}
