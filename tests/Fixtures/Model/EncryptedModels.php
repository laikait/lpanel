<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Model;

use App\Engine\Data\Repository;
use App\Engine\Model\Encrypted;
use App\Engine\Model\Model;

/** A model with two encrypted attributes, one of them nullable. */
final class Patient extends Model
{
    public function __construct(
        private ?int $id,
        private string $name,
        #[Encrypted]
        private string $diagnosis,
        #[Encrypted]
        private ?string $notes = null,
    ) {}

    public function identity(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function diagnosis(): string
    {
        return $this->diagnosis;
    }

    public function notes(): ?string
    {
        return $this->notes;
    }

    public function rediagnose(string $diagnosis): void
    {
        $this->diagnosis = $diagnosis;
    }
}

/** Same column names as Patient: a token copied between them must not decrypt. */
final class Visitor extends Model
{
    public function __construct(
        private ?int $id,
        private string $name,
        #[Encrypted]
        private string $diagnosis,
    ) {}

    public function identity(): ?int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function diagnosis(): string
    {
        return $this->diagnosis;
    }
}

/** #[Encrypted] on something that is not a string. */
final class BadlyEncrypted extends Model
{
    public function __construct(
        private ?int $id,
        #[Encrypted]
        private int $pin,
    ) {}

    public function identity(): ?int
    {
        return $this->id;
    }

    public function pin(): int
    {
        return $this->pin;
    }
}

final class PatientRepository extends Repository
{
    protected function model(): string
    {
        return Patient::class;
    }

    protected function collection(): string
    {
        return 'patients';
    }

    public function save(Patient $patient): Patient
    {
        $saved = $this->persist($patient);
        \assert($saved instanceof Patient);

        return $saved;
    }

    public function find(int $id): ?Patient
    {
        $model = $this->query()->whereIs('id', $id)->first();

        return $model instanceof Patient ? $model : null;
    }

    /** @param list<array<string, mixed>> $rows */
    public function import(array $rows): int
    {
        return $this->insertMany($rows);
    }
}
