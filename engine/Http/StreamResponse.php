<?php

declare(strict_types=1);

namespace App\Engine\Http;

/**
 * A response whose body is produced while it is being sent.
 *
 * This is the mechanism for exports and reports that must not be assembled in
 * memory first. body() is deliberately empty: there is no buffered body to
 * return, and pretending otherwise would let a filter silently materialise a
 * gigabyte.
 */
final class StreamResponse extends Response
{
    /** @var \Closure(): void|iterable<mixed, string> */
    private \Closure|iterable $producer;

    /**
     * @param \Closure(): void|iterable<mixed, string> $producer
     * @param array<string, string>                    $headers
     */
    public function __construct(\Closure|iterable $producer, int $status = 200, array $headers = [])
    {
        $this->producer = $producer;

        parent::__construct('', $status, $headers);
    }

    protected function sendBody(): void
    {
        if ($this->producer instanceof \Closure) {
            ($this->producer)();
            $this->flush();

            return;
        }

        foreach ($this->producer as $chunk) {
            echo $chunk;
            $this->flush();
        }
    }

    /**
     * Push whatever has been written so far to the client.
     *
     * ob_flush() is only legal when a buffer is actually open, which it is not
     * under every SAPI, so the level is checked first.
     */
    private function flush(): void
    {
        if (\ob_get_level() > 0) {
            \ob_flush();
        }

        \flush();
    }
}
