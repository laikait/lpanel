<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Engine\Data\ArraySource;
use App\Engine\Data\DataSource;
use App\Engine\Database\Connection;
use App\Engine\Database\ConnectionConfig;
use App\Engine\Database\SqlSource;
use App\Engine\Model\ModelException;
use App\Engine\Model\ModelManager;
use App\Engine\Security\Encrypter;
use App\Tests\Fixtures\Model\BadlyEncrypted;
use App\Tests\Fixtures\Model\Patient;
use App\Tests\Fixtures\Model\PatientRepository;
use App\Tests\Fixtures\Model\Visitor;
use App\Tests\Support\TestCase;

final class EncryptedAttributeTest extends TestCase
{
    private const KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

    private const OTHER = 'base64:ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8=';

    protected function setUp(): void
    {
        if (!\function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            self::markTestSkipped('sodium is not available.');
        }
    }

    public function test_an_encrypted_attribute_is_stored_as_a_token_and_read_back_in_the_clear(): void
    {
        $source = new ArraySource(['patients' => []]);
        $patients = new PatientRepository($source, new ModelManager(Encrypter::fromEnvironment(self::KEY)));

        $saved = $patients->save(new Patient(null, 'Ada', 'flu', 'rest'));

        self::assertSame('flu', $saved->diagnosis());
        self::assertSame('rest', $saved->notes());

        $row = $source->all('patients')[0];
        self::assertSame('Ada', $row['name']);
        self::assertIsString($row['diagnosis']);
        self::assertStringStartsWith('v1.', $row['diagnosis']);
        self::assertStringNotContainsString('flu', $row['diagnosis']);

        // A fresh manager, so the identity map cannot answer for the source.
        $reloaded = (new PatientRepository($source, new ModelManager(Encrypter::fromEnvironment(self::KEY))))->find(1);

        self::assertNotNull($reloaded);
        self::assertSame('flu', $reloaded->diagnosis());
        self::assertSame('rest', $reloaded->notes());
    }

    public function test_null_is_stored_as_null(): void
    {
        $source = new ArraySource(['patients' => []]);
        $patients = new PatientRepository($source, new ModelManager(Encrypter::fromEnvironment(self::KEY)));

        $patients->save(new Patient(null, 'Ada', 'flu'));

        self::assertNull($source->all('patients')[0]['notes']);
        self::assertNull((new PatientRepository($source, new ModelManager(Encrypter::fromEnvironment(self::KEY))))->find(1)?->notes());
    }

    public function test_a_change_is_encrypted_too(): void
    {
        $source = new ArraySource(['patients' => []]);
        $patients = new PatientRepository($source, new ModelManager(Encrypter::fromEnvironment(self::KEY)));

        $patient = $patients->save(new Patient(null, 'Ada', 'flu'));
        $patient->rediagnose('measles');
        $patients->save($patient);

        $stored = $source->all('patients')[0]['diagnosis'];
        self::assertIsString($stored);
        self::assertStringNotContainsString('measles', $stored);
        self::assertSame('measles', (new PatientRepository($source, new ModelManager(Encrypter::fromEnvironment(self::KEY))))->find(1)?->diagnosis());
    }

    public function test_bulk_inserts_are_encrypted(): void
    {
        $source = new ArraySource(['patients' => []]);
        $patients = new PatientRepository($source, new ModelManager(Encrypter::fromEnvironment(self::KEY)));

        $patients->import([['id' => 7, 'name' => 'Grace', 'diagnosis' => 'cold', 'notes' => null]]);

        self::assertStringStartsWith('v1.', (string) $source->all('patients')[0]['diagnosis']);
        self::assertSame('cold', $patients->find(7)?->diagnosis());
    }

    public function test_it_survives_a_real_database(): void
    {
        if (!\in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        $connection = new Connection(ConnectionConfig::of('encrypted', 'sqlite::memory:'));
        $connection->execute('CREATE TABLE patients (id INTEGER PRIMARY KEY, name TEXT NOT NULL, diagnosis TEXT NOT NULL, notes TEXT NULL)');
        $source = new SqlSource($connection);

        $patients = new PatientRepository($source, new ModelManager(Encrypter::fromEnvironment(self::KEY)));
        $id = $patients->save(new Patient(null, 'Ada', 'flu', 'rest'))->identity();
        self::assertIsInt($id);

        $raw = $connection->scalar('SELECT diagnosis FROM patients WHERE id = ?', [$id]);
        self::assertIsString($raw);
        self::assertStringStartsWith('v1.', $raw);

        $reloaded = (new PatientRepository($source, new ModelManager(Encrypter::fromEnvironment(self::KEY))))->find($id);
        self::assertNotNull($reloaded);
        self::assertSame('flu', $reloaded->diagnosis());
        self::assertSame('rest', $reloaded->notes());
    }

    public function test_a_retired_key_still_reads(): void
    {
        $source = new ArraySource(['patients' => []]);
        (new PatientRepository($source, new ModelManager(Encrypter::fromEnvironment(self::OTHER))))
            ->save(new Patient(null, 'Ada', 'flu'));

        $rotated = new ModelManager(Encrypter::fromEnvironment(self::KEY, self::OTHER));

        self::assertSame('flu', (new PatientRepository($source, $rotated))->find(1)?->diagnosis());
    }

    public function test_a_value_that_does_not_decrypt_is_an_error_not_a_null(): void
    {
        $source = new ArraySource(['patients' => []]);
        (new PatientRepository($source, new ModelManager(Encrypter::fromEnvironment(self::OTHER))))
            ->save(new Patient(null, 'Ada', 'flu', 'rest'));

        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('"diagnosis" is #[Encrypted] but the stored value does not decrypt');

        (new PatientRepository($source, new ModelManager(Encrypter::fromEnvironment(self::KEY))))->find(1);
    }

    public function test_plain_text_left_in_the_column_is_refused(): void
    {
        $models = new ModelManager(Encrypter::fromEnvironment(self::KEY));

        $this->expectException(ModelException::class);

        $models->hydrate(Patient::class, ['id' => 1, 'name' => 'Ada', 'diagnosis' => 'flu']);
    }

    public function test_a_token_does_not_decrypt_in_another_models_column(): void
    {
        $models = new ModelManager(Encrypter::fromEnvironment(self::KEY));
        $row = $models->seal(Patient::class, ['id' => 1, 'name' => 'Ada', 'diagnosis' => 'flu']);

        $this->expectException(ModelException::class);

        $models->hydrate(Visitor::class, $row);
    }

    public function test_a_token_does_not_decrypt_in_another_column_of_the_same_model(): void
    {
        $models = new ModelManager(Encrypter::fromEnvironment(self::KEY));
        $row = $models->seal(Patient::class, ['id' => 1, 'name' => 'Ada', 'diagnosis' => 'flu', 'notes' => 'rest']);
        [$row['diagnosis'], $row['notes']] = [$row['notes'], $row['diagnosis']];

        $this->expectException(ModelException::class);

        $models->hydrate(Patient::class, $row);
    }

    public function test_only_strings_can_be_encrypted(): void
    {
        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('must be declared string or ?string, not int');

        (new ModelManager(Encrypter::fromEnvironment(self::KEY)))->hydrate(BadlyEncrypted::class, ['id' => 1, 'pin' => 1234]);
    }

    public function test_without_an_encrypter_it_refuses_rather_than_storing_plain_text(): void
    {
        $patients = new PatientRepository(new ArraySource(['patients' => []]), new ModelManager());

        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('has no Encrypter');

        $patients->save(new Patient(null, 'Ada', 'flu'));
    }

    public function test_the_container_gives_the_manager_the_applications_encrypter(): void
    {
        $container = $this->application()->container();
        $container->instance(Encrypter::class, Encrypter::fromEnvironment(self::KEY));
        $container->instance(DataSource::class, new ArraySource(['patients' => []]));

        $patients = $container->get(PatientRepository::class);
        self::assertSame('flu', $patients->save(new Patient(null, 'Ada', 'flu'))->diagnosis());
    }
}
