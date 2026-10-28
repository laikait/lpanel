<?php

declare(strict_types=1);

namespace App\Tests\Feature;

use App\Modules\Operations\Catalogue\Catalogue;
use App\Modules\Operations\Catalogue\Shape;
use PHPUnit\Framework\TestCase;

/**
 * The panel's catalogue mirrors the agent's registry exactly.
 *
 * tests/Fixtures/lpa/catalogue.json is a copy of the lpa repository's
 * internal/ops/testdata/catalogue.json: the registry's definitions and a real
 * result of each operation, written by the Go tests. When the agent's
 * registry changes, regenerate it there (-update) and copy it again.
 */
final class OperationCatalogueTest extends TestCase
{
    /** @return list<\stdClass> */
    private static function agentCatalogue(): array
    {
        $file = \json_decode((string) \file_get_contents(\dirname(__DIR__) . '/Fixtures/lpa/catalogue.json'), false, 32, \JSON_THROW_ON_ERROR);
        self::assertInstanceOf(\stdClass::class, $file);
        self::assertIsArray($file->operations);
        $entries = [];

        foreach ($file->operations as $entry) {
            self::assertInstanceOf(\stdClass::class, $entry);
            $entries[] = $entry;
        }

        return $entries;
    }

    public function test_every_definition_matches_the_agents_registry(): void
    {
        $catalogue = new Catalogue();
        $agent = [];

        foreach (self::agentCatalogue() as $entry) {
            $agent[$entry->op . '@' . $entry->op_version] = $entry;
        }

        $panel = [];

        foreach ($catalogue->all() as $definition) {
            $panel[$definition->op . '@' . $definition->version] = $definition;
        }

        \ksort($agent);
        \ksort($panel);
        self::assertSame(\array_keys($agent), \array_keys($panel), 'The two sides list different operations.');

        foreach ($panel as $key => $d) {
            $a = $agent[$key];
            self::assertSame([$a->mutating, $a->idempotent, $a->scope, $a->timeout_seconds, $a->max_result_bytes], [$d->mutating, $d->idempotent, $d->scope, $d->timeoutSeconds, $d->maxResultBytes], $key);
        }
    }

    public function test_every_real_result_passes_its_result_schema(): void
    {
        $catalogue = new Catalogue();

        foreach (self::agentCatalogue() as $entry) {
            $definition = $catalogue->find($entry->op, $entry->op_version);
            self::assertNotNull($definition);
            self::assertNull($definition->result->check($entry->example_result), $entry->op);
        }
    }

    /**
     * THREAT_MODEL.md test 4, the panel's half: the read-only operations take
     * nothing; the account operations take exactly a user name the panel
     * chose; the website operations take checked settings (ADR-0014), each
     * field by pattern; and nothing takes a command or an absolute path.
     */
    public function test_no_operation_takes_a_command_a_path_or_an_environment(): void
    {
        $hostile = ['{"command":"id"}', '{"path":"/etc/shadow"}', '{"env":{"A":"1"}}', '[]', '"x"', 'null', '1'];
        $accounts = [];
        $websites = [];

        foreach ((new Catalogue())->all() as $d) {
            foreach ($hostile as $payload) {
                self::assertNotNull($d->payload->check(\json_decode($payload, false)), \sprintf('%s accepted %s', $d->op, $payload));
            }

            if (!$d->mutating) {
                self::assertSame(['servers.read', 'server'], [$d->capability, $d->scope], $d->op);
                self::assertNull($d->payload->check(new \stdClass()), $d->op);

                continue;
            }

            if ($d->op === 'websites.check') {
                $websites[] = $d->op;
                self::assertSame(['websites.manage', 'server', true], [$d->capability, $d->scope, $d->idempotent]);
                self::assertNull($d->payload->check(new \stdClass()));

                continue;
            }

            if ($d->scope === 'website') {
                $websites[] = $d->op;
                self::assertSame([$d->op === 'website.update' ? 'websites.configure' : 'websites.manage', true], [$d->capability, $d->idempotent], $d->op);
                $owner = '"account_id":"01JB2W5Z6X7Y8Z9A0B1C2D3E4F","system_user":"lpabcdefgh"';
                $settings = static fn(string $domains = '"example.com"', string $root = '"example.com"', string $headers = '[]', string $redirects = '[]'): string => \sprintf(
                    '"domains":[%s],"document_root":%s,"php":true,"index":[],"redirects":%s,"headers":%s',
                    $domains,
                    $root,
                    $redirects,
                    $headers,
                );
                $extra = $d->op === 'website.update' ? ',"replace_edits":false' : '';
                $good = \in_array($d->op, ['website.create', 'website.update'], true) ? '{' . $owner . ',' . $settings() . $extra . '}' : '{' . $owner . '}';
                self::assertNull($d->payload->check(\json_decode($good, false)), $d->op);

                if ($good === '{' . $owner . '}') {
                    continue;
                }

                foreach ([
                    $settings('"a.com; include /etc/shadow"'),
                    $settings('"example.com"', '"/etc/lpa"'),
                    $settings('"example.com"', '"../../etc"'),
                    $settings('"example.com"', '"example.com"', '[{"name":"X-A","value":"$request_uri"}]'),
                    $settings('"example.com"', '"example.com"', '[{"name":"X-A","value":"a\" always; include /x; #"}]'),
                    $settings('"example.com"', '"example.com"', '[]', '[{"from":"~ .*","to":"/x","code":301}]'),
                    $settings('"example.com"', '"example.com"', '[]', '[{"from":"/a","to":"/$host","code":301}]'),
                    $settings() . ',"raw":"server {}"',
                ] as $members) {
                    $payload = '{' . $owner . ',' . $members . $extra . '}';
                    self::assertNotNull($d->payload->check(\json_decode($payload, false)), \sprintf('%s accepted %s', $d->op, $payload));
                }

                continue;
            }

            $accounts[] = $d->op;
            self::assertSame(['accounts.manage', 'account', true], [$d->capability, $d->scope, $d->idempotent], $d->op);
            self::assertNull($d->payload->check(\json_decode('{"system_user":"lpabcdefgh"}', false)), $d->op);

            foreach (['{}', '{"system_user":"root"}', '{"system_user":"lpabcdefgh","home":"/"}', '{"system_user":"../etc/x"}', '{"system_user":"lpabcdefg1"}', '{"system_user":"lpABCDEFGH"}'] as $payload) {
                self::assertNotNull($d->payload->check(\json_decode($payload, false)), \sprintf('%s accepted %s', $d->op, $payload));
            }
        }

        \sort($accounts);
        \sort($websites);
        self::assertSame(['account.create', 'account.delete', 'account.suspend', 'account.unsuspend'], $accounts);
        self::assertSame(['website.create', 'website.delete', 'website.suspend', 'website.unsuspend', 'website.update', 'websites.check'], $websites);
    }

    public function test_results_are_checked_strictly(): void
    {
        $info = (new Catalogue())->find('system.info', 1);
        self::assertNotNull($info);
        $good = \json_decode('{"os":{"id":"debian","version_id":"13","version_codename":"trixie","pretty_name":"Debian"},"kernel":"6.12","hostname":"web-01","cpu_count":2,"memory_total_bytes":1024,"architecture":"amd64"}', false);
        self::assertNull($info->result->check($good));

        $bad = [
            'an unknown field' => '{"os":{"id":"debian","version_id":"13","version_codename":"trixie","pretty_name":"Debian"},"kernel":"6.12","hostname":"web-01","cpu_count":2,"memory_total_bytes":1024,"architecture":"amd64","shell":"/bin/sh"}',
            'a missing field' => '{"os":{"id":"debian","version_id":"13","version_codename":"trixie","pretty_name":"Debian"},"kernel":"6.12","hostname":"web-01","cpu_count":2,"memory_total_bytes":1024}',
            'a list for an object' => '{"os":[],"kernel":"6.12","hostname":"web-01","cpu_count":2,"memory_total_bytes":1024,"architecture":"amd64"}',
            'a string for a number' => '{"os":{"id":"debian","version_id":"13","version_codename":"trixie","pretty_name":"Debian"},"kernel":"6.12","hostname":"web-01","cpu_count":"2","memory_total_bytes":1024,"architecture":"amd64"}',
            'a negative size' => '{"os":{"id":"debian","version_id":"13","version_codename":"trixie","pretty_name":"Debian"},"kernel":"6.12","hostname":"web-01","cpu_count":2,"memory_total_bytes":-1,"architecture":"amd64"}',
            'a control character' => '{"os":{"id":"debian","version_id":"13","version_codename":"trixie","pretty_name":"Deb\u001bian"},"kernel":"6.12","hostname":"web-01","cpu_count":2,"memory_total_bytes":1024,"architecture":"amd64"}',
            'a hostname with a slash' => '{"os":{"id":"debian","version_id":"13","version_codename":"trixie","pretty_name":"Debian"},"kernel":"6.12","hostname":"../etc","cpu_count":2,"memory_total_bytes":1024,"architecture":"amd64"}',
            'null' => 'null',
        ];

        foreach ($bad as $why => $json) {
            self::assertNotNull($info->result->check(\json_decode($json, false)), $why);
        }
    }

    public function test_go_nils_are_null_and_allowed_only_where_go_sends_them(): void
    {
        $catalogue = new Catalogue();
        $health = $catalogue->find('agent.health', 1);
        $metrics = $catalogue->find('system.metrics', 1);
        self::assertNotNull($health);
        self::assertNotNull($metrics);

        self::assertNull($health->result->check(\json_decode('{"started_at":1,"pending_results":0,"last_errors":null}', false)));
        self::assertNull($health->result->check(\json_decode('{"started_at":1,"pending_results":0,"last_errors":[{"code":"timeout","at":5}]}', false)));
        self::assertNotNull($health->result->check(\json_decode('{"started_at":null,"pending_results":0,"last_errors":null}', false)));
        self::assertNull($metrics->result->check(\json_decode('{"load":{"one":0,"five":0.5,"fifteen":1},"memory":{"total_bytes":1,"available_bytes":1,"swap_total_bytes":0,"swap_free_bytes":0},"uptime_seconds":5,"disks":null}', false)));
        self::assertNotNull($metrics->result->check(\json_decode('{"load":null,"memory":{"total_bytes":1,"available_bytes":1,"swap_total_bytes":0,"swap_free_bytes":0},"uptime_seconds":5,"disks":null}', false)));
    }

    public function test_a_map_takes_only_operation_names(): void
    {
        $shape = Shape::map(Shape::int(), 3, '/^[a-z]+\.[a-z]+$/');
        self::assertNull($shape->check(\json_decode('{"system.info":1}', false)));
        self::assertNotNull($shape->check(\json_decode('{"rm -rf /":1}', false)));
        self::assertNotNull($shape->check(\json_decode('{"a.a":1,"b.b":1,"c.c":1,"d.d":1}', false)), 'too many');
        self::assertNotNull($shape->check([]), 'a list is not a map');
    }
}
