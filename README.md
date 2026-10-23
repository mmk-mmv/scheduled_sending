# Scheduled Sending for Roundcube

[![Regression tests](https://github.com/mmk-mmv/scheduled_sending/actions/workflows/ci.yml/badge.svg)](https://github.com/mmk-mmv/scheduled_sending/actions/workflows/ci.yml)

A maintained GPL-3.0 fork of [Gene Hawkins / texxasrulez's Scheduled Sending](https://github.com/texxasrulez/scheduled_sending). Schedule mail in Roundcube, then let a local CLI worker deliver it after the browser has closed.

**1.4.0-beta.3 — preview release.** This fork changes delivery, credentials, queue actions and compose submission. Review the upgrade notes before deploying. Automated tests use transport doubles; they do not establish compatibility with every mail server, attachment plugin or custom skin.

## What changed

- Authenticated SMTP connection and captured, encrypted per-account SMTP/IMAP credentials, independent of the web session. No PHP `mail()` fallback or public HTTP worker.
- UTF-8 MIME headers for subjects and display names, transport-safe text/HTML and RFC2231 attachment filenames. Existing queued UTF-8 headers are normalized at their normal send time; message bodies are preserved.
- Named/multiple recipients, quoted commas, Unicode display names, To/Cc/Bcc and Cc-only/Bcc-only messages. Bcc stays out of transmitted headers.
- Compose body and attachments belong to the selected compose context. Missing attachments and oversized messages fail before scheduling.
- One transaction and a unique request key prevent duplicate scheduling when a slow browser request is retried. The original request wins; a retry cannot overwrite it.
- A taskbar queue with a count badge, local send time, visible failure reason and owner-scoped `.eml` download. Queued HTML is downloaded as an attachment, never rendered in the webmail origin.
- Explicit owner, CSRF, ID and state checks for mutations. UI changes are confirmed only after a database change succeeds.
- A database lock and atomic claim prevent concurrent workers from submitting the same job. Ambiguous SMTP failures and interrupted jobs stay held for investigation.
- Temporary SMTP connection failures retry before submission, with bounded attempts. Sent-folder recovery retries IMAP only, never SMTP.
- PHP, JavaScript and real MariaDB regression tests, with pinned official Roundcube parser sources.

See [the review of all eight pre-existing forks](docs/FORK_REVIEW.md), [changelog](CHANGELOG.md) and [credits](CREDITS.md). Changes maintained by the fork contributors, 2026-10-23. Original Git history and license are retained.

## Supported scope

Roundcube **1.6.19 / 1.6.x**, MySQL or MariaDB **InnoDB**, PHP **8.1–8.5**, OpenSSL and mbstring. CI uses MariaDB 11.4 and the official Roundcube 1.6.19 parser. Elastic is the primary UI target; inherited Larry CSS remains, with the same shared JavaScript handler. Custom skins need their own browser verification. PostgreSQL/SQLite and Roundcube 1.7/2.x are not supported by this release.

This implementation requires reusable password-based SMTP and IMAP authentication. OAuth refresh tokens, interactive MFA and passwordless login are not implemented. The worker also needs the same Roundcube configuration, encryption key, plugin dependencies and file access as the web application. Changing a mailbox password or the encryption key after scheduling can prevent delivery; the job is held visibly rather than silently sent through another transport.

## Installation

1. Back up the plugin, Roundcube configuration and queue table. Put this repository at `ROUND CUBE ROOT/plugins/scheduled_sending` (the directory name must be exactly `scheduled_sending`). Keep code owned by the administrator; the web user needs read access, not write access to PHP sources.
2. For a **new** installation, import [SQL/mysql.initial.sql](SQL/mysql.initial.sql) into the Roundcube database. Existing installations must retain their queue and verify the `dedupe_key` column with its **unique index**. Do not reimport the initial schema over an existing table.
3. Copy `config.inc.php.dist` to `config.inc.php`. Adjust the display timezone and limits. The canonical table setting is `db_table_scheduled_sending`; a legacy `scheduled_sending_table` setting must agree if present.
4. Add the plugin to the existing Roundcube plugin list, preserving other entries:

   ```php
   $config['plugins'] = array_values(array_unique(array_merge(
       $config['plugins'] ?? [], ['scheduled_sending']
   )));
   ```

5. Check [operations and upgrade notes](docs/OPERATIONS.md), then install a timer or cron job under the **Roundcube service account**, using the correct PHP CLI binary. Example invocation:

   ```sh
   /usr/bin/php /var/www/roundcube/plugins/scheduled_sending/bin/worker_cli.php
   ```

   **This worker submits due messages.** Installation tests must use `tests/`, not the worker. [Example systemd files](examples/) require path/user adjustments before enabling.

No public worker URL, webmail password in cron, worker token or HTTP TLS exception is required. `bin/scheduled_queue_worker.php` is a compatibility alias for the local worker; former HTTP worker actions return 403.

### Plesk

Plesk's bundled Roundcube can load plugins. Keep custom activation in Plesk's supported local configuration override, preserve its plugin list, and use the same PHP and service user as webmail. Common paths are `/usr/share/psa-roundcube`, `/etc/psa-webmail/roundcube/config.local.php` and `/opt/plesk/php/8.3/bin/php`; discover and verify the actual installation first. Package updates can replace bundled files, so retain this version in Git and verify the plugin and timer after an update. A separate custom Roundcube installation has separate configuration and is not changed by this setup.

## Using the queue

Compose a message: the send time defaults to **tomorrow at 08:00 in your browser's local timezone**. You can change the date and time freely; an existing selection is preserved. Select **Send later** to schedule. The editor stays open on failure and leaves only after the queue transaction is confirmed. A pending request disables the button. Retrying the same compose operation resolves the original job; use a fresh compose operation to schedule an intentionally separate message.

Open **Scheduled messages** in the taskbar or Settings. The badge includes queued, retrying, processing and held/problem jobs, not only mail waiting for its time. The queue page lists up to 200 active/problem rows. Cancel or postpone a waiting job; download the queued `.eml` to inspect it locally. Error details explain held jobs. Processing, completed and Sent-copy-recovery jobs cannot be requeued or deleted through these actions; held errors cannot be rescheduled. Cancellation retains the payload until explicitly deleted.

The queue page uses browser-local time. Preferences and confirmation messages use `scheduled_timezone`; database schedule values are UTC. A normal timer tick runs once a minute, so the selected time is the earliest eligible time, not a promise of delivery at an exact second.

## Verification without sending mail

```sh
tests/fetch_roundcube.sh
ROUNDCUBE_CORE="$PWD/.cache/roundcube" php tests/test_reliability.php
ROUNDCUBE_CORE="$PWD/.cache/roundcube" php tests/test_unicode.php
python3 tests/test_unicode_mime.py
node tests/test_compose.js
node tests/test_settings_queue.js
node tests/test_queue.js
```

The fetch script pins the official Roundcube commit and PEAR Mail_Mime 1.10.13, verifying both archive SHA-256 hashes. Unicode tests encode real synthetic MIME and decode it with Roundcube and the independent Python standard email parser. Test SMTP/IMAP classes are local doubles; no production configuration or mailbox is bootstrapped. For real SQL ownership/transaction tests, use a disposable database named **exactly `scheduled_sending_test`**:

```sh
ROUNDCUBE_CORE="$PWD/.cache/roundcube" \
TEST_MYSQL_DSN='mysql:host=127.0.0.1;port=3306;dbname=scheduled_sending_test' \
TEST_MYSQL_USER=root TEST_MYSQL_PASSWORD='' php tests/test_database.php
```

**The database test drops/recreates `scheduled_queue` in that test database.** It refuses any other database name. Its transport classes remain test doubles. CI runs this suite across the PHP matrix.

For an installed server, `bin/healthcheck_cli.php` reads queue health without changing messages. Its optional `--check-auth` performs SMTP/IMAP login/logout, with no SMTP MAIL/RCPT/DATA and no IMAP message writes. It is not an end-to-end delivery test. See [security](SECURITY.md) before sharing diagnostics.

## License

[GNU GPL version 3](LICENSE). You may use, modify and publish a fork under its terms. Preserve license/copyright notices, identify changed files and dates, and provide corresponding source when distributing the software. Archiving the upstream repository does not cancel that license. This fork is independent and does not imply endorsement by the original author.
