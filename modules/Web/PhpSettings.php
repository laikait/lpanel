<?php

declare(strict_types=1);

namespace App\Modules\Web;

use App\Engine\Database\ConnectionManager;
use App\Engine\Database\Query\QueryBuilder;

/**
 * The owner's php.ini settings, per PHP version their sites use. They go
 * into the account's PHP-FPM pool for that version, so they apply to every
 * site of the account on that version.
 *
 * Only the directives below may be set, each with the same value rule as
 * the agent's (internal/web/features.go). A setting left empty is PHP's own
 * default.
 */
final class PhpSettings
{
    /**
     * directive => [value rule, kind, choices for the form]. kind is
     * "size", "number", "flag", "choice" or "zone".
     *
     * @var array<string, array{rule: string, kind: string, choices?: list<string>}>
     */
    public const DIRECTIVES = [
        'memory_limit' => ['rule' => '/^([1-9][0-9]{1,4}M|[1-9]G)$/', 'kind' => 'choice', 'choices' => ['64M', '128M', '256M', '512M', '768M', '1G']],
        'upload_max_filesize' => ['rule' => '/^([1-9][0-9]{0,4}M|[1-9]G)$/', 'kind' => 'choice', 'choices' => ['2M', '8M', '32M', '64M', '128M', '256M', '512M', '1G']],
        'post_max_size' => ['rule' => '/^([1-9][0-9]{0,4}M|[1-9]G)$/', 'kind' => 'choice', 'choices' => ['8M', '32M', '64M', '128M', '256M', '512M', '1G']],
        'max_execution_time' => ['rule' => '/^[0-9]{1,4}$/', 'kind' => 'number'],
        'max_input_time' => ['rule' => '/^(-1|[0-9]{1,4})$/', 'kind' => 'number'],
        'max_input_vars' => ['rule' => '/^[1-9][0-9]{2,5}$/', 'kind' => 'number'],
        'session.gc_maxlifetime' => ['rule' => '/^[1-9][0-9]{1,6}$/', 'kind' => 'number'],
        'date.timezone' => ['rule' => '#^[A-Za-z_]{1,32}(/[A-Za-z0-9_+-]{1,32}){0,2}$#', 'kind' => 'zone'],
        'error_reporting' => ['rule' => '/^E_ALL( & ~E_(DEPRECATED|NOTICE|STRICT|WARNING))*$/', 'kind' => 'choice', 'choices' => ['E_ALL', 'E_ALL & ~E_DEPRECATED', 'E_ALL & ~E_DEPRECATED & ~E_NOTICE', 'E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING']],
        'display_errors' => ['rule' => '/^(On|Off)$/', 'kind' => 'flag'],
        'log_errors' => ['rule' => '/^(On|Off)$/', 'kind' => 'flag'],
        'allow_url_fopen' => ['rule' => '/^(On|Off)$/', 'kind' => 'flag'],
        'short_open_tag' => ['rule' => '/^(On|Off)$/', 'kind' => 'flag'],
        'output_buffering' => ['rule' => '/^(On|Off|[0-9]{1,6})$/', 'kind' => 'choice', 'choices' => ['Off', '4096', 'On']],
    ];

    public function __construct(private readonly ConnectionManager $connections) {}

    /** @return array<string, string> one version's settings, directive => value */
    public function of(int $accountId, string $version): array
    {
        $out = [];

        foreach ($this->table()->where('account_id', $accountId)->where('version', $version)->orderBy('directive')->get() as $row) {
            $out[(string) $row['directive']] = (string) $row['value'];
        }

        return $out;
    }

    /** @return array<string, array<string, string>> every version's, version => directive => value */
    public function all(int $accountId): array
    {
        $out = [];

        foreach ($this->table()->where('account_id', $accountId)->orderBy('directive')->get() as $row) {
            $out[(string) $row['version']][(string) $row['directive']] = (string) $row['value'];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input directive => value; empty means PHP's default
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function validate(array $input): array
    {
        $clean = [];
        $errors = [];

        foreach ($input as $directive => $value) {
            $directive = (string) $directive;
            $value = \trim((string) $value);

            if (!isset(self::DIRECTIVES[$directive])) {
                $errors[$directive] = 'Web.error_php_directive';
            } elseif ($value !== '') {
                if (self::DIRECTIVES[$directive]['kind'] === 'zone' && !\in_array($value, \timezone_identifiers_list(), true)) {
                    $errors[$directive] = 'Web.error_php_value';
                } elseif (\preg_match(self::DIRECTIVES[$directive]['rule'], $value) !== 1) {
                    $errors[$directive] = 'Web.error_php_value';
                } else {
                    $clean[$directive] = $value;
                }
            }
        }

        \ksort($clean);

        return [$clean, $errors];
    }

    /**
     * Replace one version's settings.
     *
     * @param array<string, string> $settings from validate()
     */
    public function replace(int $accountId, string $version, array $settings): void
    {
        $this->table()->where('account_id', $accountId)->where('version', $version)->delete();

        foreach ($settings as $directive => $value) {
            $this->table()->insert(['account_id' => $accountId, 'version' => $version, 'directive' => $directive, 'value' => $value]);
        }
    }

    public function forget(int $accountId): void
    {
        $this->table()->where('account_id', $accountId)->delete();
    }

    private function table(): QueryBuilder
    {
        return $this->connections->connection()->table('php_settings');
    }
}
