# Updating the framework

An LPHP application is a copy of the framework, not a package in `vendor/`:
`engine/`, `laika` and `public/index.php` sit in the same tree as your
`modules/`, `config/` and `templates/`. So `composer update` never brings a new
framework version — it only updates `vendor/`. `framework:update` does.

```bash
php laika framework:update --check      # installed vs latest; changes nothing
php laika framework:update --dry-run    # what would change; changes nothing
php laika framework:update              # update to the latest release
php laika framework:rollback            # undo it
```

Do it on a development copy, run your tests, commit, and deploy the result like
any other change — between `php laika down` and `php laika up` (see
[Maintenance mode](running.md#maintenance-mode)) if the deployment migrates. Never run it on a production server that is serving requests:
for a moment the tree holds files from both versions.

## What it changes, and what it never touches

Every release ships `framework.json`: each file, its SHA-256, and whose it is.
The update compares three things for every file — what the installed release
shipped, what the new one ships, and what is on your disk.

| The file is | Examples | What an update does |
|---|---|---|
| **The framework's** | `engine/`, `laika`, `server`, `public/index.php`, the framework's tests in `tests/`, `docs/`, `phpunit.xml`, `.env.example` | Replaces it, adds new ones, deletes dropped ones — **if you have not edited it** |
| **Seeded, then yours** | `modules/`, `templates/`, `lang/`, `config/`, `public/assets/` | Adds a file that is new in the release; **never replaces one**. When the release changed one you have, its copy is written next to yours as `<file>.dist` |
| **Merged** | `composer.json` | The framework's package constraints are updated; your own packages, scripts and autoload entries are kept |
| **In no manifest** | your tests in `tests/Unit` and `tests/Feature`, your modules, `.env` | Never read, never written |

`vendor/`, `system/`, `.git/` and `.env` are never written, whatever a manifest
says.

**Commit `framework.json`.** It is how the next update knows which files you
edited.

## When you edited a framework file

If a framework file differs from what your installed release shipped, and the
new release changes it too, the update **stops and changes nothing**:

```
Changed here (1):
  engine/Http/Cookie.php  (edited here)
      diff -u engine/Http/Cookie.php system/Runtime/update/3.1.0/lphp/engine/Http/Cookie.php
```

Compare the two. The lasting fix is to move your change out of `engine/` — into
a module, through a [hook or filter](../reference/hooks-and-filters.md) — and
put the file back. Then run the update again.

`--force` replaces the edited files with the release's instead. Your copies are
in the backup, so nothing is lost.

## Backups and rollback

Before writing anything, the update copies every file it will replace or delete
into `system/Backups/framework-<from>-to-<to>-<time>/`. `framework:rollback`
restores them, deletes the files the update added, and puts `composer.json` and
`framework.json` back. Rolling back twice undoes the update before that.

## After an update

The update prints the [`UPGRADING.md`](../../UPGRADING.md) sections between your
version and the new one, then what to run:

```bash
composer update        # if composer.json changed
php laika migrate      # if the framework or a module added migrations
php laika cache:clear
php laika security:check
vendor/bin/phpunit     # your tests and the framework's
git diff               # review, then commit — framework.json included
```

It runs none of these itself: Composer may not be installed where you run it,
and a migration is your decision.

## Choosing the version

| | |
|---|---|
| `--to=3.1.0` | a specific release instead of the latest |
| `--from=lphp-v3.1.0.zip` | a release you downloaded, for a machine without internet access; the zip is checked against `lphp-v3.1.0.zip.sha256` when that file sits next to it |
| `--major` | required to cross a major version (3.x to 4.0), after reading `UPGRADING.md` |
| `--force` | also needed to go to an older release |

Releases come from [github.com/laikait/lphp](https://github.com/laikait/lphp/releases):
`lphp-vX.Y.Z.zip`, its `.sha256`, and its `framework.json`. A zip that does not
match its checksum is refused before it is unpacked.

## An application older than framework.json

An application created before manifests existed has no `framework.json`. The
update downloads the manifest of the version it reports (`php laika about`) and
uses that, so edited files are still found. Offline, pass it yourself:
`--baseline=path/to/that/framework.json`.

## If you keep the framework as a git remote

Some teams track the framework in git instead:

```bash
git remote add lphp https://github.com/laikait/lphp.git
git fetch lphp --tags
git merge v3.1.0
```

Git then shows a conflict wherever you and the release changed the same lines.
It does not protect seeded files the way the update does: a template you edited
and the release also changed is a merge conflict to resolve by hand.

## If it doesn't work

| What you see | Why | Fix |
|---|---|---|
| "changed here, so nothing was updated" | You edited a framework file the release also changed | Move the change into a module, or `--force` |
| "framework.json is missing and the manifest … could not be found" | No manifest, and no network or no such release | `--baseline=<framework.json of your version>` |
| "does not match its published SHA-256 checksum" | A corrupt or altered download | Download it again |
| "a major upgrade" | The release crosses a major version | Read `UPGRADING.md`, then `--major` |
| Class not found after updating | `vendor/` and the autoloader are from before | `composer update`, or `composer dump-autoload` |
