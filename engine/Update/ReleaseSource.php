<?php

declare(strict_types=1);

namespace App\Engine\Update;

/**
 * Where releases come from: GitHub, or an archive already on this machine.
 */
interface ReleaseSource
{
    /** The newest release's version, e.g. "3.1.0". */
    public function latest(): string;

    /**
     * Download, verify and unpack $version under $workDirectory.
     *
     * @throws UpdateException
     */
    public function fetch(string $version, string $workDirectory): Release;

    /** The manifest $version shipped with, without fetching the whole release; null if it has none. */
    public function manifest(string $version): ?Manifest;

    /** For messages: "github.com/laikait/lphp", "lphp-v3.1.0.zip". */
    public function describe(): string;
}
