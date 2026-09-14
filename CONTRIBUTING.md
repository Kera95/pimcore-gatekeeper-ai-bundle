# Contributing

Thanks for helping to improve the Gatekeeper AI bundle. Issues and pull requests are welcome.

## Branches

| Branch | Purpose |
|---|---|
| `master` | Latest stable, released code. Every release is a tag on this branch. |
| `develop` | Integration branch. All features land here first. |
| `feature/<name>` | New work, branched from `develop`, merged back into `develop` by pull request. |
| `fix/<name>` | Fixes for a released version, branched from `master`, merged into `master` by pull request; `master` is then merged back into `develop`. |

Releases are cut by merging `develop` into `master` (pull request, or `git merge --no-ff develop`),
tagging the merge commit (`vX.Y.Z`), pushing the tag and publishing a GitHub release. Update
`CHANGELOG.md` in the same change.

## Pull requests

- Branch from the right base (see above) and keep one topic per pull request.
- Keep the code compatible with PHP 8.1 and Pimcore 11.x, 12.x and 2026.x. No PHP 8.2+ only syntax.
- Add or adjust tests. Unit tests live under `tests/Unit` and must stay runnable without a
  database; anything that needs a real save, the database or a console command belongs into the
  functional suite under `tests/Functional` (see the *Testing* section of the README).
- Run both suites before pushing:

  ```bash
  composer install
  vendor/bin/codecept run Unit
  PIMCORE_TEST_DB_DSN=mysql://root:root@127.0.0.1:3306/tsf_gatekeeper_ai_test vendor/bin/codecept run Functional
  ```

  From inside a Pimcore project that consumes the bundle as a path package, run them with the
  project's vendor directory: `cd bundles/Tsf/GatekeeperAiBundle && ../../../vendor/bin/codecept run Unit`
  (the functional suite needs the project's test database, e.g. `docker compose run --rm test-php ...`).
- Keep the static analysis and the code style clean. Both tools live in the bundle's own
  `require-dev`, so they need `composer install` in this directory — the consuming project's
  vendor directory does not ship them:

  ```bash
  composer phpstan
  composer cs:check    # composer cs:fix writes the changes
  ```

- CI runs both suites against Pimcore 11.x (PHP 8.1), 12.x (PHP 8.3) and 2026.x (PHP 8.5) with a
  MariaDB service, and PHPStan plus PHP-CS-Fixer in a separate workflow; all of them must pass.
- Describe behaviour changes in `CHANGELOG.md` under *Unreleased* and in `README.md` when the
  configuration or a command changes.

## Reporting bugs

Please include the Pimcore and PHP versions, the relevant part of `tsf_gatekeeper_ai.yaml`, the
output of `bin/console tsf:gatekeeper:ai:validate`, and what you expected to happen.
