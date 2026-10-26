<?php

declare(strict_types=1);

namespace App\Engine\Scheduler;

/**
 * The three things a schedule can be.
 *
 * Three rather than one, because each maps onto a mechanism the framework
 * already has: a console command dispatched in process, a job pushed onto the
 * queue, or a callback called through the container. None of them is new
 * execution machinery -- a schedule decides *when*, and something that already
 * existed decides *what*.
 *
 * There is deliberately no fourth case for a shell command. Shelling out means
 * locating a PHP binary, quoting a command line for two operating systems and
 * losing the container, the configuration and the log -- and everything that
 * would be reached for it is better written as a command in the module that
 * owns the work.
 */
enum ScheduleTarget: string
{
    /** A registered console command, run in the scheduler's own process. */
    case Command = 'command';

    /** A job, pushed onto the queue. Under the sync store that runs it inline. */
    case Job = 'job';

    /** A closure, called through the container so its parameters are injected. */
    case Callback = 'callback';
}
