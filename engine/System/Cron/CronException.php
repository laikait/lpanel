<?php

declare(strict_types=1);

namespace App\Engine\System\Cron;

use App\Engine\System\SystemException;

/**
 * A cron job, or a crontab, could not be handled safely.
 *
 * Several of these are refusals to guess. A crontab belongs to a person before
 * it belongs to this framework, so when the managed block is not exactly what
 * this code wrote -- half a block, two blocks, a line edited by hand -- the
 * answer is to stop and say so, never to rewrite what might be somebody's work.
 */
class CronException extends SystemException
{
    public static function invalidId(string $id): CronValidationException
    {
        return new CronValidationException(\sprintf(
            'Cron job id "%s" is invalid. An id is up to 100 lowercase letters, digits, dot, dash, colon '
            . 'and underscore, starting with a letter or digit, such as "billing.invoice-check".',
            $id,
        ));
    }

    public static function invalidOwner(string $owner): CronValidationException
    {
        return new CronValidationException(\sprintf(
            'Cron owner "%s" is invalid. An owner is up to 64 lowercase letters, digits, dot, dash and '
            . 'underscore, and names this application in the crontab.',
            $owner,
        ));
    }

    /** The scheduler's own exception, chained, says which field is wrong. */
    public static function invalidSchedule(string $id, \Throwable $previous): CronValidationException
    {
        return new CronValidationException(\sprintf(
            'Cron job "%s" has an unusable schedule. It is five cron fields or a macro such as @daily; '
            . 'the previous exception says what is wrong with it.',
            $id,
        ), 0, $previous);
    }

    public static function unsupportedCommand(string $id, string $why): CronValidationException
    {
        return new CronValidationException(\sprintf('Cron job "%s" cannot be written to a crontab: %s.', $id, $why));
    }

    public static function invalidLog(string $id): CronValidationException
    {
        return new CronValidationException(\sprintf(
            'Cron job "%s" has an unusable log path. It is an absolute path with no line breaks and no ".." segment; '
            . 'null discards output.',
            $id,
        ));
    }

    public static function corruptBlock(string $owner, string $why): self
    {
        return new self(\sprintf(
            'The crontab block for "%s" is not one this framework wrote (%s), so it will not be changed. '
            . 'Fix it by hand with crontab -e.',
            $owner,
            $why,
        ));
    }

    public static function editedByHand(string $owner, string $id): self
    {
        return new self(\sprintf(
            'Cron job "%s" in the block for "%s" has been edited by hand: its line no longer matches what '
            . 'was installed. Nothing was changed. Undo the edit, or remove the job and install it again.',
            $id,
            $owner,
        ));
    }

    public static function unsupportedPlatform(): self
    {
        return new self('Cron is managed with crontab, which this operating system does not have. It is Linux-only.');
    }

    public static function unreadable(?int $exitCode): self
    {
        return new self(\sprintf(
            'The crontab could not be read (crontab -l exited with %s).',
            $exitCode === null ? 'no exit code' : (string) $exitCode,
        ));
    }

    public static function unwritable(?int $exitCode): self
    {
        return new self(\sprintf(
            'The crontab could not be written (crontab exited with %s). Nothing was installed.',
            $exitCode === null ? 'no exit code' : (string) $exitCode,
        ));
    }
}
