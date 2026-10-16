# Dependency refresh — October 16, 2026

Base: `origin/develop` at `fb13fb5d9dd29e11bc91cb12af9dc5e241b97d25`.
Scope: Composer dependencies, CI actions, runner images, container tools, and typos.

## Changes

- Infection 0.29.14 → 0.32.6, the latest release supporting PHP 8.2. Its new default excludes uncovered code; `--with-uncovered` and the existing harness assertion preserve the previous mutation-score denominator.
- Transitive major upgrades: sanmai/pipeline 6.22 → 7.12 and webmozart/assert 1.12.1 → 2.5.0; no SDK source changes were needed.
- PHPStan 2.3.0 → 2.3.1 and Psalm 6.19.1 → 6.20.0. Composer constraints retain their existing caret style and now start at the selected releases.
- Hadolint 2.14.0 → 2.15.1, actionlint 1.7.8 → 1.7.12, zizmor 1.16.0 → 1.31.1, Gitleaks 8.28.0 → 8.30.1, php-extension-installer → 2.12.1, and typos 1.50.3 → 1.51.1.
- GitHub actions: harden-runner 2.22.0 → 2.22.1, create-github-app-token 3.2.0 → 3.3.0, upload-artifact 7.0.1 → 7.0.2, download-artifact 8.0.1 → 8.0.2, scorecard-action 2.4.4 → 2.5.0, and codeql-action 4.38.2 → 4.39.0.
- CI installs now pin just 1.59.0. The actionlint configuration allows the GA Ubuntu 26.04 label missing from actionlint 1.7.12. A local zizmor annotation retains create-pull-request for signed App commits and idempotent PR updates.
- Hosted runner: Ubuntu 24.04 → 26.04, [generally available since September 17](https://github.com/actions/runner-images/issues/14747).
- All action refs were checked against stable release commits; all image digests were obtained from registry manifests. All five Linux wheel hashes match the [PyPI release metadata](https://pypi.org/pypi/typos/1.51.1/json). The sdist remains excluded because building it requires Rust.

## Holds

- PHP 8.2–8.5, Node 22, Python 3.14, and Composer's PHP 8.2.27 platform describe the supported or selected runtimes and stay unchanged. Their existing image digests are current for those tag families.
- Guzzle 7.15.5, Promises 2.5.3, and PSR-7 2.13.1 remain the latest working release lines. [Guzzle 8](https://github.com/guzzle/guzzle/blob/8.2.0/UPGRADING.md) removes handler context used by `RetryMiddleware::provablyNotSent`, removes `ClientInterface::getConfig`, and rejects cURL options on StreamHandler. A safe upgrade requires a separate migration of retry guarantees for payments/account mutations and the no-cURL transport. This refresh does not change those guarantees.
- Infection 0.35.8 requires PHP 8.3; Pint 1.32.1 requires PHP 8.3; PHPUnit 13.4.3 requires PHP 8.4.1; coverage 14.4.1 requires PHP 8.4. Keep Infection 0.32.6, Pint 1.30.4, PHPUnit 11.5.57, and coverage 11.0.12 for the PHP 8.2 matrix. Newer transitive packages requiring newer PHP are constrained by the same platform.
- BusyBox 1.36-musl remains the [upstream stable line](https://busybox.net/); 1.37 and 1.38 are labeled unstable. Its digest matches `stable-musl`.
- Xdebug 3.5.3, ShellCheck 0.11.0, oasdiff 1.33.0, checkout 7.0.1, setup-just 4.0.0, dependency-review-action 5.0.0, create-pull-request 8.1.1, and release-please-action 5.0.0 are current stable releases.
- `conformance-ref` stays at v1.0.0, the only published tag. Generated code and the OpenAPI snapshot are unchanged.
- TypeScript 7, typescript-eslint, `@types/node`, Go, and uv holds do not apply: this PHP repository has no such language manifests.

## Validation

Validation applies to the dependency changes in this commit. Heavy commands ran through `/home/vasiliid/My/Projects/Hushscript/scripts/heavy.sh`.

- PHP 8.5.11 with Xdebug 3.5.3: Pint, PHPStan, Psalm taint analysis, Composer validation, dependency analysis, and the locked advisory audit passed.
- `php -d auto_prepend_file="$PWD/scripts/coverage-filter.php" vendor/bin/phpunit --coverage-php /tmp/deps-sdk-php-native/coverage.php`: 2,315 tests and 27,555 assertions passed. Coverage: 98.76% lines and 92.86% branches, above the 90%/85% floors.
- Infection 0.32.6 dry run on `RetryPolicy.php`, using the existing PHPUnit wrapper and `--with-uncovered --only-covering-test-cases`: 113 mutants generated; adapter/configuration smoke passed. This was a dry run and does not establish a mutation score.
- `CONFORMANCE_STRICT=1 php conformance/run.php` against the pinned v1.0.0 native mock: 34 passed, zero failed or skipped. `php scripts/run-examples.php` passed against the same mock. The mock process was stopped afterward.
- `node --test scripts/release-candidate.test.mjs`: all 30 passed on Node 24.21.0.
- Hadolint 2.15.1, actionlint 1.7.12, zizmor 1.31.1, ShellCheck, Gitleaks 8.30.1, typos 1.51.1, `just --summary`, and `git diff --check` passed. The new runner label configuration, explicit just version, and narrow PR-action annotation resolve findings introduced by the updated infrastructure linters.
- Native `composer --no-plugins archive --format=tar`, runtime-only dependency installation beside the extracted archive, and SDK/generated-catalog loading passed. This supplements the Docker build gate; it does not establish Docker image acceptance.
- Independent review verified action SHA/version comments, registry digests, wheel hashes, the lockfile, runtime dependency preservation, and the Infection compatibility change.

Docker is unavailable in the dispatched sandbox. Docker recipes were not run: `just ci`, `just lint`, `just test`, `just conformance`, `just examples`, `just build`, `just mutation`, `just regen`, and `just smoke`. The controller must run `just ci` for all four PHP versions and `just mutation` for the full nightly mutation gate. PHP 8.2–8.4 execution and full mutation acceptance remain unverified. Regeneration and live smoke are outside this refresh. No push, merge, release, or publication is part of this change.
