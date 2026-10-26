<?php

declare(strict_types=1);

namespace App\Engine\Migration;

use App\Engine\Error\FrameworkException;

/** Why migrations or seeders could not be run, undone or found. Each message names what to do. */
final class MigrationException extends FrameworkException
{
    public static function badName(string $module, string $file): self
    {
        return new self(\sprintf(
            '%s has a migration named "%s", which does not sort into a run order. Name it '
            . 'YYYY_MM_DD_HHMMSS_what_it_does.php, in lower case: 2026_09_19_120000_create_invoices.php.',
            $module,
            $file,
        ));
    }

    public static function notAMigration(string $id, string $returned): self
    {
        return new self(\sprintf(
            'The file of %s returned %s. A migration file returns its migration: '
            . 'return new class implements Migration { ... };',
            $id,
            $returned,
        ));
    }

    /**
     * A migration that failed. Where the database rolls structure back, nothing
     * of it is left; where it does not, what ran before the failure stays.
     *
     * The failure itself is the previous exception, not part of this message:
     * it came from a migration's code or the database and may carry anything,
     * so it is shown under its own rules -- see disclosesCause().
     */
    public static function failed(string $id, string $driver, bool $undone, \Throwable $previous): self
    {
        return new self(
            \sprintf('%s failed on %s.', $id, $driver)
                . ($undone
                    ? ' Nothing of it was kept, and it was not recorded.'
                    : \sprintf(' It was not recorded, but %s commits each change of structure as it runs, so what '
                        . 'ran before the failure is still there. Undo that by hand, or fix the migration so it can '
                        . 'run again over it.', $driver)),
            0,
            $previous,
        );
    }

    /**
     * Whether what went wrong underneath may be shown as it is.
     *
     * The rule the console applies to any exception: a message the framework
     * wrote in full, yes; one that repeats something from outside -- a
     * database's own error, a migration's exception -- only in debug mode,
     * because it may carry a credential.
     */
    public function disclosesCause(bool $debug): bool
    {
        $previous = $this->getPrevious();

        return $previous !== null
            && ($debug || ($previous instanceof FrameworkException && $previous->disclosesMessage()));
    }

    public static function locked(string $connection, int $seconds): self
    {
        return new self(\sprintf(
            'Another run is migrating the "%s" connection; it was waited for %d seconds. Try again when it has '
            . 'finished. Migrations run one at a time, so that two deployments cannot run the same one twice.',
            $connection,
            $seconds,
        ));
    }

    /** @param list<string> $ids */
    public static function irreversible(array $ids): self
    {
        return new self(\sprintf(
            'Nothing was rolled back: %s cannot be undone. A migration that can implements Reversible and has a '
            . 'down(). Undo it with a new migration instead.',
            \implode(', ', $ids),
        ));
    }

    public static function badSeederName(string $module, string $file): self
    {
        return new self(\sprintf(
            '%s has a seeder named "%s". Name it in lower case, digits and underscores, which sort into the order '
            . 'they run: countries.php, or 01_countries.php.',
            $module,
            $file,
        ));
    }

    public static function notASeeder(string $id, string $returned): self
    {
        return new self(\sprintf(
            'The file of %s returned %s. A seeder file returns its seeder: return new class implements Seeder { ... };',
            $id,
            $returned,
        ));
    }

    /** As failed(): the cause is the previous exception, shown under disclosesCause(). */
    public static function seedFailed(string $id, string $driver, \Throwable $previous): self
    {
        return new self(
            \sprintf('%s failed on %s. Nothing of it was kept; the seeders before it were.', $id, $driver),
            0,
            $previous,
        );
    }

    /** @param list<string> $enabled */
    public static function unknownModule(string $module, array $enabled): self
    {
        return new self(\sprintf(
            'There is no enabled module "%s". The enabled ones are: %s.',
            $module,
            $enabled === [] ? 'none' : \implode(', ', $enabled),
        ));
    }

    /** @param list<string> $ids */
    public static function missingFiles(array $ids): self
    {
        return new self(\sprintf(
            'Nothing was rolled back: %s ran, but the file is gone, so there is no down() to run. '
            . 'Restore the file, or remove the row from the migrations table by hand if it no longer matters.',
            \implode(', ', $ids),
        ));
    }
}
