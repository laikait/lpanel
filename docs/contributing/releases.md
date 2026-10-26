# API stability

Every public class and every public surface — hook names, configuration keys,
console commands, the error document, asset URLs — is marked **Stable**,
**Experimental**, **Internal** or **Deprecated** in
[`STABILITY.md`](../../STABILITY.md), as specification §53 asks. The short version:

- **Nothing is Stable before 1.0.0.** The specification says not to promise
  stability too early, and a framework no independent application has used yet
  is early. What is expected to become Stable is marked *1.0 candidate*.
- **Experimental** is what modules and applications are written against: the
  `module.php` contract, the ten helpers, handlers' types, stores' interfaces. It
  changes only in a minor release, and never without an entry in
  [`UPGRADING.md`](../../UPGRADING.md).
- **Internal** is wiring — kernels, registries behind collectors, resolvers, the
  framework's own commands, concrete stores selected by name — and changes
  whenever it needs to.
- **Deprecated** is empty. A deprecation is announced by `@deprecated`, the
  changelog, the upgrade notes and `STABILITY.md` — never by
  `E_USER_DEPRECATED`, which the error handler would turn into an exception.
- **Persisted formats are the exception to "Internal means no notice".** A queued
  job and a session record outlive the deployment that wrote them, so a release
  must read what the previous one wrote.

`tests/Architecture/DocumentationTest.php` keeps the file honest: every engine
class resolves to exactly one level, no row is stale, nothing is Stable in 0.x,
and **no module in `modules/` or in the showcase may use an Internal class** —
the rule that makes the classification mean something.

## Versioning and releases

**Semantic versioning, with 0.x read the way SemVer allows.** Before 1.0.0, a
minor release (`0.1` → `0.2`) may change Experimental APIs, with upgrade notes; a
patch release (`0.1.0` → `0.1.1`) may not. From 1.0.0, Stable APIs change only in
a major release, after a deprecation in a minor one.

**The version is written in one place**, `Application::VERSION`, which `about`
and `help` print. `composer.json` deliberately has no `version` field: Composer
takes a package's version from its git tag, and a second copy is a copy that
disagrees. A module's own `version()` is independent of the framework's — it is
what other modules' constraints are checked against — and `modules/Shared`
changes version only when its own contract does.

**A release, step by step:**

1. `composer check` green locally, and CI green on both jobs: the gate on
   8.2–8.5 and the 8.2 runtime job, which installs `--no-dev`, lints, boots and
   serves the default pages.
2. The checks in [Deployment and security](../operations/deployment.md) through a
   real web server, not only the test suite — every security defect this project
   has found late was found that way.
3. `composer bench -- --compare=<the previous release's saved run>` on the same
   machine. Informational: a slower number is a question to answer in the
   changelog, not a gate.
4. `CHANGELOG.md`: rename `[Unreleased]` to `[X.Y.Z] - YYYY-MM-DD` and start a new
   empty `[Unreleased]`. Every **Changed** or **Removed** entry has its section in
   `UPGRADING.md`, and `STABILITY.md` reflects anything promoted or deprecated.
5. Set `Application::VERSION` to `X.Y.Z`. `DocumentationTest` fails if it and the
   changelog disagree.
6. Commit, then an annotated tag: `git tag -a vX.Y.Z -m "X.Y.Z"`, and push the tag.
7. The tag starts `.github/workflows/release.yml`, which builds what applications
   update from: `lphp-vX.Y.Z.zip` (`git archive` of the tag, so
   `.gitattributes` decides what ships), `framework.json` inside it and beside it
   (`php laika framework:manifest`), and a `.sha256`. It fails if
   `Application::VERSION` is not the tag. See
   [Updating the framework](../operations/updating.md).

Nothing in this list is automated, on purpose: a release is the one moment
somebody should be reading what changed, and a script that tags on green is a
script that releases whatever happened to pass.
