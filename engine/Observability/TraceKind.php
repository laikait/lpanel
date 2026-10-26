<?php

declare(strict_types=1);

namespace App\Engine\Observability;

/** What a unit of work is. It says which log lines belong to a request and which to a job. */
enum TraceKind: string
{
    /** A request through the HTTP kernel, asset requests included. */
    case Http = 'http';

    /** One run of console. A worker is one of these, with a job trace per job inside it. */
    case Console = 'console';

    /** One job, run by a worker or synchronously where it was dispatched. */
    case Job = 'job';

    /**
     * Whatever happens before, or outside, any of the above: bootstrapping, a
     * test calling the kernel directly, a script embedding the framework.
     */
    case Process = 'process';
}
