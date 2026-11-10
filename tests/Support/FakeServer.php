<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * The Linux side of hosting accounts, as the agent keeps it, for feature
 * tests: users with a shell, a quota and a suspended flag, and the agent's
 * journal, so the same operation id gets the first outcome however often it
 * is asked (what makes retries safe).
 */
final class FakeServer
{
    /** @var array<string, array{uid: int, shell: string, suspended: bool, quota: array<string, int>, used: int}> */
    public array $users = [];

    /** @var list<string> operations actually carried out, not answered from the journal */
    public array $executed = [];

    /** While true, every request fails as if the socket were gone. */
    public bool $down = false;

    /** Carry out the next change, then lose the reply (the panel crashed, the connection broke). */
    public bool $loseNextReply = false;

    /** @var array{op: string, code: string, message: string}|null the next time this op runs, it fails */
    public ?array $failNext = null;

    /** @var array<string, array<string, mixed>> request id => response */
    private array $journal = [];

    private int $nextUid = 1001;

    public function agent(): FakeAgent
    {
        $answers = [];

        foreach (['account.create', 'account.modify', 'account.suspend', 'account.unsuspend', 'account.terminate'] as $op) {
            $answers[$op] = fn(array $args, string $id): array => $this->change($op, $args, $id);
        }

        $answers['account.info'] = fn(array $args): array => $this->read($args);
        $answers['system.facts'] = FakeAgent::unavailable();

        return new FakeAgent($answers);
    }

    /** How many times an operation was really carried out. */
    public function ran(string $op): int
    {
        return \count(\array_filter($this->executed, static fn(string $e): bool => $e === $op));
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function change(string $op, array $args, string $id): array
    {
        if ($this->down) {
            throw FakeAgent::unavailable();
        }

        if (isset($this->journal[$id])) {
            return $this->journal[$id];
        }

        $this->executed[] = $op;
        $response = $this->run($op, $args);
        $this->journal[$id] = $response;

        if ($this->loseNextReply) {
            $this->loseNextReply = false;

            throw FakeAgent::unavailable();
        }

        // terminate runs in the background: the first answer is "accepted".
        return $op === 'account.terminate' && $response['status'] === 'done' ? ['status' => 'accepted'] : $response;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function run(string $op, array $args): array
    {
        $name = (string) ($args['username'] ?? '');

        if ($this->failNext !== null && $this->failNext['op'] === $op) {
            $fail = $this->failNext;
            $this->failNext = null;

            return ['status' => 'failed', 'error' => ['code' => $fail['code'], 'message' => $fail['message']]];
        }

        if ($op === 'account.create') {
            if (isset($this->users[$name])) {
                return ['status' => 'failed', 'error' => ['code' => 'exists', 'message' => 'a user named ' . $name . ' already exists']];
            }

            $this->users[$name] = ['uid' => $this->nextUid++, 'shell' => (string) $args['shell'], 'suspended' => false, 'quota' => $args['quota'], 'used' => 8192];

            return ['status' => 'done', 'result' => $this->describe($name)];
        }

        if ($op === 'account.terminate') {
            $existed = isset($this->users[$name]);
            unset($this->users[$name]);

            return ['status' => 'done', 'result' => ['username' => $name, 'existed' => $existed]];
        }

        if (!isset($this->users[$name])) {
            return ['status' => 'failed', 'error' => ['code' => 'no_such_account', 'message' => 'there is no user named ' . $name]];
        }

        match ($op) {
            'account.modify' => $this->users[$name] = ['shell' => (string) ($args['shell'] ?? $this->users[$name]['shell']), 'quota' => $args['quota'] ?? $this->users[$name]['quota']] + $this->users[$name],
            'account.suspend' => $this->users[$name]['suspended'] = true,
            'account.unsuspend' => $this->users[$name]['suspended'] = false,
            default => null,
        };

        return ['status' => 'done', 'result' => $this->describe($name)];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function read(array $args): array
    {
        if ($this->down) {
            throw FakeAgent::unavailable();
        }

        $names = \is_array($args['usernames'] ?? null) ? $args['usernames'] : \array_keys($this->users);
        $accounts = [];

        foreach ($names as $name) {
            if (isset($this->users[$name])) {
                $accounts[] = $this->describe($name);
            }
        }

        return ['status' => 'done', 'result' => ['accounts' => $accounts, 'missing' => []]];
    }

    /** @return array<string, mixed> */
    private function describe(string $name): array
    {
        $u = $this->users[$name];

        return [
            'username' => $name, 'uid' => $u['uid'], 'gid' => $u['uid'], 'home' => '/home/' . $name,
            'shell' => $u['shell'], 'suspended' => $u['suspended'],
            'usage' => ['used_bytes' => $u['used'], 'used_inodes' => 12, 'limit_kib' => $u['quota']['blocks_kib'] ?? 0, 'inode_limit' => $u['quota']['inodes'] ?? 0],
        ];
    }
}
