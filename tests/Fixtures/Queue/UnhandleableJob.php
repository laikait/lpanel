<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Queue;

use App\Engine\Queue\Job;

/** A job with nothing to do, which is a mistake the dispatch should catch. */
final class UnhandleableJob implements Job {}
