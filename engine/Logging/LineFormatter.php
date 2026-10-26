<?php

declare(strict_types=1);

namespace App\Engine\Logging;

/**
 * A record as one line of text.
 *
 *     2026-09-13T14:02:11.482+00:00 ERROR [app] Payment declined {"order":41}
 *
 * One line per record, always, including when the message contains newlines --
 * a multi-line log entry breaks every tool that reads logs a line at a time,
 * starting with grep and tail. Newlines in the message are escaped rather than
 * stripped so that nothing is silently lost, and so that a message cannot forge
 * a second log entry by containing one.
 *
 * The timestamp comes first because sorting a log file should be sorting text.
 * The context comes last as JSON because that is the part a program reads and
 * the part a person skips.
 */
final class LineFormatter
{
    /**
     * @param bool $includePrefix the timestamp and level, which a system logger
     *                            adds for itself and would otherwise say twice
     */
    public function __construct(
        private readonly bool $includeContext = true,
        private readonly bool $includePrefix = true,
    ) {}

    public function format(LogRecord $record): string
    {
        $line = $this->includePrefix
            ? \sprintf(
                '%s %s [%s] %s',
                $record->at()->format('Y-m-d\TH:i:s.vP'),
                \strtoupper($record->level->label()),
                $record->channel,
                self::oneLine($record->message),
            )
            : \sprintf('[%s] %s', $record->channel, self::oneLine($record->message));

        if (!$this->includeContext || $record->context === []) {
            return $line;
        }

        return $line . ' ' . self::encode($record->context);
    }

    /**
     * The context as JSON, or a marker if it somehow still will not encode.
     *
     * Context has already been normalised, so this should not fail. "Should not
     * fail" is not "cannot fail", and a log line is written at exactly the
     * moment when an exception from the logger is least welcome.
     *
     * @param array<array-key, mixed> $context
     */
    private static function encode(array $context): string
    {
        $json = \json_encode(
            $context,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        return $json === false ? '{"context":"[unencodable]"}' : self::oneLine($json);
    }

    private static function oneLine(string $text): string
    {
        return \str_replace(["\r\n", "\n", "\r"], ['\\n', '\\n', '\\n'], $text);
    }
}
