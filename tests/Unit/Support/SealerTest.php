<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Engine\Cache\Stores\FileStore;
use App\Engine\Queue\QueuedJob;
use App\Engine\Queue\QueueException;
use App\Engine\Support\Sealer;
use App\Tests\Fixtures\Queue\RecordingJob;
use App\Tests\Support\TestCase;

/**
 * Bytes that are about to be unserialize()d are checked first, so a cache or
 * queue entry somebody else wrote never becomes an object.
 */
final class SealerTest extends TestCase
{
    private string $directory = '';

    protected function tearDown(): void
    {
        foreach (\glob($this->directory . '/*/*') ?: [] as $file) {
            @\unlink($file);
        }

        foreach (\glob($this->directory . '/*') ?: [] as $dir) {
            @\rmdir($dir);
        }

        if ($this->directory !== '') {
            @\rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_what_it_sealed_it_opens(): void
    {
        $sealer = new Sealer(\random_bytes(32));
        $sealed = $sealer->seal('a:1:{i:0;s:1:"x";}');

        self::assertStringStartsWith(Sealer::PREFIX, $sealed);
        self::assertSame('a:1:{i:0;s:1:"x";}', $sealer->open($sealed));
        self::assertSame($sealed, $sealer->seal('a:1:{i:0;s:1:"x";}'), 'not deterministic, so forgetIf() could not find a row');
    }

    public function test_anything_else_is_refused(): void
    {
        $sealer = new Sealer(\random_bytes(32));
        $sealed = $sealer->seal('O:8:"stdClass":0:{}');

        self::assertNull($sealer->open('O:8:"stdClass":0:{}'), 'unsealed bytes');
        self::assertNull($sealer->open(\substr($sealed, 0, -1) . '}}'), 'changed bytes');
        self::assertNull((new Sealer(\random_bytes(32)))->open($sealed), 'another key');
        self::assertNull($sealer->open(Sealer::PREFIX . 'short'), 'a truncated header');
    }

    public function test_purposes_get_different_keys(): void
    {
        $cache = Sealer::derive('secret', 'cache');

        self::assertNull(Sealer::derive('secret', 'queue')->open($cache->seal('x')));
        self::assertSame('x', Sealer::derive('secret', 'cache')->open($cache->seal('x')));
    }

    public function test_without_a_key_nothing_changes(): void
    {
        $none = Sealer::derive('', 'cache');

        self::assertFalse($none->isActive());
        self::assertSame('raw', $none->seal('raw'));
        self::assertSame('raw', $none->open('raw'));
        self::assertNull($none->open((new Sealer('k'))->seal('raw')), 'sealed under a key that is gone');
    }

    /** A file somebody planted in the cache directory is a miss, not an object. */
    public function test_a_planted_cache_file_is_a_miss(): void
    {
        $this->directory = \sys_get_temp_dir() . '/sealer-' . \bin2hex(\random_bytes(6));
        $store = new FileStore($this->directory, sealer: new Sealer(\random_bytes(32)));
        $store->put('page', 'genuine', null);

        $files = \glob($this->directory . '/*/*') ?: [];
        self::assertCount(1, $files);
        \file_put_contents($files[0], \serialize(['key' => 'page', 'expires' => null, 'value' => new \ArrayObject([1])]));

        self::assertNull($store->get('page'));
    }

    public function test_a_forged_job_is_never_unserialized(): void
    {
        $sealer = new Sealer(\random_bytes(32));
        $forged = new QueuedJob(id: '1', queue: 'default', class: RecordingJob::class, payload: \serialize(new RecordingJob()));

        try {
            $forged->job($sealer);
            self::fail('A payload this application did not seal was unserialized.');
        } catch (QueueException $e) {
            self::assertStringContainsString('APP_KEY', $e->getMessage());
        }

        $genuine = new QueuedJob(id: '2', queue: 'default', class: RecordingJob::class, payload: $sealer->seal(\serialize(new RecordingJob())));

        self::assertInstanceOf(RecordingJob::class, $genuine->job($sealer));
    }
}
