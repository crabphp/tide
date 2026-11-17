# Contributing

Use PHP 8.4 with PDO SQLite, PDO MySQL and the framework's required extensions. Keep this checkout beside `framework/`; Composer uses that path repository. Read [ARCHITECTURE.md](ARCHITECTURE.md) and the framework's [conventions](../framework/AGENTS.md).

```sh
set -e
composer install
composer format:check
composer lint
composer analyze
vendor/bin/phpunit
CRAB_TEST_MYSQL_DSN='mysql:host=127.0.0.1;dbname=crab_test' CRAB_TEST_MYSQL_USER=root CRAB_TEST_MYSQL_PASSWORD='' vendor/bin/phpunit
```

SQLite always runs. Setting the MySQL DSN also runs each integration case with PDO and the asynchronous driver. Use a disposable database: tests replace their tables. The named-database test creates and drops a uniquely named `crab_tide_*` schema, requiring permission to do so. No Tide test is skipped on SQLite. The framework's own full suite must also stay green after a framework change.

`composer analyze` runs Mago's static analysis against `mago-baseline.toml`, the findings recorded when it was first set up, so only new ones fail. Fix a recorded one where you touch the code, then shrink the baseline with `vendor/bin/mago analyze --generate-baseline --baseline mago-baseline.toml`; never regenerate it to hide a new finding. `composer format` applies Mago formatting. Do not weaken boundary checks or existing tests. The package depends only on contracts and the `ColumnName` attribute; real-driver setup and statement instrumentation stay in tests. README examples are executed from their text. The standalone reading benchmark is described in [BENCHMARKS.md](BENCHMARKS.md).
