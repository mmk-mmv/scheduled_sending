# Security

The maintained release line is 1.4.x; the initial 1.4.0-beta.1 is a preview. Report suspected vulnerabilities through this repository's **Report a vulnerability** / private advisory feature when available. Do not put passwords, authentication tokens, encryption keys, database dumps, `.eml` files or real correspondence into public issues. If private reporting is unavailable, open a minimal issue asking for a private channel, without exploit details or private data.

This plugin stores reusable SMTP and IMAP passwords encrypted with Roundcube's key to send after logout. It does not provide end-to-end encryption or protection against compromise of both the queue database and that key. Restrict access and backups accordingly. Debug logs may expose addresses/subjects/attachment metadata; keep them disabled normally and sanitize diagnostics.

Queue operations require an authenticated owner and mutation request checks. Downloaded `.eml` attachments are owner-scoped and never rendered as HTML in Roundcube. Old public HTTP worker entry points are disabled. Tests stub SMTP and IMAP, and SQL tests refuse a database name other than `scheduled_sending_test`.

A held SMTP error or stale processing job can represent uncertain acceptance. Investigate before any manual resend. Automatic requeue of ambiguous jobs is intentionally unsupported.
