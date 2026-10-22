# Verification

## Executed integration tests

The integration suite runs against an installed Moodle PHPUnit database using native backup/restore, enrolment, question, quiz-attempt and gradebook APIs. No production/student data was used.

| Environment | Result |
| --- | --- |
| Moodle 5.1.3, build 20260216; PHP 8.3.6; MariaDB 10.11.7 | 9 tests, 142 assertions passed |
| Moodle 5.1.8+, build 20261021; PHP 8.3.6; MariaDB 10.11.7 | 9 tests, 142 assertions passed |

Coverage includes event class/callback existence; signed message expiry, tampering and replay; HTTP lab opt-in; scoped fixed/random question backup; attachment exclusion; native restore; stable resource UUID across replacement; duplicate operation idempotency; LTI account binding reuse; non-editing instructor role; group membership and overrides; suspension; real attempt creation/submission and grade event queueing; refusal to release a freeze with actual attempts; canonical source LTI grade item and grade ordering; source admission and suspended users; privacy erasure of cached mixed-user packages; and new-quiz checkbox persistence.

These are simulated source/target flows within isolated Moodle fixtures. They do not assert that a browser has completed an LTI 1.3 launch across two HTTP servers. The browser/network/local-installation checks remain in [the acceptance checklist](ACCEPTANCE.ar.md).

## Teacher export regression (2026-10-22)

The local two-site lab exposed `error/setting_locked_by_permission` while exporting a quiz for a standard editing teacher. Moodle locks the `users` backup setting at false for a teacher without `moodle/backup:userinfo`; assigning false to that already-disabled setting still throws. ExamSync now leaves already-disabled settings untouched. Settings that are enabled still go through Moodle's normal setter, so permission and configuration locks are not overridden and an enabled locked setting still stops export.

`test_teacher_export_with_permission_locked_user_data` reproduces the teacher-owned backup while cron runs as admin, checks the disabled permission lock, exports the scoped quiz, and verifies that native user records and unrelated questions are excluded. This regression was added after the nine-test runs recorded above; those historical results do not include it. Run it in a disposable Moodle PHPUnit environment before claiming full integration validation of this fix:

```bash
vendor/bin/phpunit --colors=never --filter test_teacher_export_with_permission_locked_user_data public/local/examsync/tests/integration_test.php
```

## Reproduce

Use a disposable Moodle 5.1 development checkout with its Composer development dependencies. Install both plugin folders with `tools/install.py`. Configure an isolated PHPUnit database prefix and dataroot in Moodle `config.php`; never point PHPUnit at a live site's data.

From the Moodle root (parent of `public`):

```bash
composer install
php public/admin/tool/phpunit/cli/init.php --disable-composer
vendor/bin/phpunit --colors=never public/local/examsync/tests/integration_test.php
```

The host must meet Moodle's PHPUnit requirements, including the `en_AU.UTF-8` locale. Moodle creates and resets test data using `phpunit_prefix` and `phpunit_dataroot`.

From this repository:

```bash
python3 tools/verify.py
python3 tools/package.py
```

The verification helper checks PHP syntax and XML parsing. The package helper creates two Moodle plugin ZIPs, a complete source ZIP, and SHA-256 checksums under `dist`. GitHub Actions performs those static checks and packages the artifacts. The full Moodle suite must run in a configured Moodle test environment.

## Before release

Run the local acceptance checklist on the exact question types, access rules, reverse proxy and database versions in use. Record the browser SSO result, student attempt/grade result and disconnection behavior. Do not promote this Alpha to production based only on syntax checks or an API health response.
