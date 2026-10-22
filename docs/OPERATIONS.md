# Operation and upgrades

## Upgrade from the archived upstream

Stop only the scheduled-sending timer while taking the backup and replacing files. Do not clear the queue. Verify the existing table is InnoDB and has `dedupe_key varchar(64)` with a unique index; this release uses that existing upstream column. Back up before adding a missing index and check existing non-null keys for duplicates first. Old rows with null keys remain valid, but retries of pre-upgrade browser requests cannot be deduplicated retroactively.

New jobs capture SMTP and IMAP credentials during the authenticated session. Old queued rows without captured credentials are **not repaired automatically**. Existing errors stay held. Review status/payload and mail-server acceptance before deciding what to do. Do not bulk-update errors or `processing` rows to `queued`.

The web application and worker must share Roundcube's stable encryption key, database and configuration. If either table alias is configured, both must agree. Refresh cached browser assets after replacement. Remove old HTTP/curl worker cron jobs; run exactly the CLI worker instead. Before restarting the timer, inspect queued work because the next worker run will deliver due jobs.

## States and recovery

| State | Meaning and action |
| --- | --- |
| `queued` | Waiting until the UTC schedule; can cancel or postpone. |
| `retry_wait` | SMTP connection failed before submission; bounded retry after the delay. |
| `processing` | Atomically claimed. A stale row needs manual acceptance checks, never automatic requeue. |
| `error` | Held: bad payload, authentication failure, exhausted connection attempts or potentially ambiguous delivery. Read the reason and check server logs. |
| `sent_copy_error` | SMTP accepted; only Sent-folder filing is pending. The worker retries IMAP, never SMTP. |
| `sent` | Submitted and Sent filing completed; queued MIME and encrypted passwords cleared. |
| `canceled` / legacy `cancelled` | No worker submission; payload remains until explicitly deleted. |

SMTP cannot guarantee exactly-once delivery after a connection/process failure. This worker deliberately prefers a held job to a possible duplicate. `Message-ID` lookup helps avoid duplicate Sent copies; it does not prove whether an external recipient received a message. Never requeue merely because a browser timed out.

## Timer and monitoring

Adapt the files under `examples/` to the actual installation. Run as the same service user and PHP version as Roundcube. The CLI removes Roundcube's inherited web-request time limit; systemd still imposes a final process timeout. The batch budget is checked **between** messages; one connection/message operation may last longer. The database lock serializes workers even if another timer is accidentally installed.

Inspect timer state and recent service output with systemctl/journalctl. Nonzero exit means a delivery/Sent-copy failure or configuration error; `retry_wait` alone is a recoverable state. `Persistent=true` resumes timer processing after server downtime; the machine must be running to send. Expired credentials, disk/database failures and outages still require operation and monitoring.

The healthcheck reads at most 200 unfinished rows; it is a diagnostic, not a complete mail audit. It flags old `processing` rows but does not release them. Optional `--check-auth` only logs in/out. The normal worker **does send**, including if invoked manually for troubleshooting.

## Privacy, storage and resource limits

Pending jobs contain full message MIME, recipients and encrypted reusable account passwords. Restrict database access/backups and the encryption key. A database plus key compromise can expose account passwords. Do not enable verbose protocol logging for scheduled credentials. Completed jobs remove MIME/password ciphertext; canceled/error jobs retain them to support review and explicit deletion. Decide an appropriate retention policy for your installation; this release does not silently purge pending mail.

The default maximum is 8 MiB including MIME/base64 overhead, independently of webmail's normal attachment limit. Check PHP memory and the database packet limit before increasing it. Unsupported attachment storage backends may fail the completeness check; this is safer than delivering a message with missing attachments. OAuth, DKIM/S/MIME signing plugins, remote attachment plugins and unusual skins need separate integration testing. Normal outgoing server signing remains the mail server's responsibility.
