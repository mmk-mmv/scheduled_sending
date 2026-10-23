# Changelog

All notable changes to `scheduled_sending` should be documented in this file.

## [1.4.0-beta.4] - 2026-10-23

- Default to today at 08:00 before 08:00, otherwise tomorrow at 08:00, in the browser's local timezone. Preserve an existing selection and use calendar days across daylight-saving changes.

## [1.4.0-beta.3] - 2026-10-23

- Encode UTF-8 subjects/display names as RFC2047 and attachment filenames as RFC2231; use quoted-printable for newly generated plain text/HTML.
- Normalize legacy queued UTF-8 headers at normal delivery without changing schedule, body or attachment bytes; leave already encoded MIME intact.
- Preserve Bcc in the saved Draft while stripping it from SMTP/Sent output.
- Add real encoder/decoder and independent Python MIME round-trip regressions for umlauts, ß, emoji, long subjects, text/HTML, filenames and binary content. Make assertion failures exit nonzero despite Roundcube's generic fatal-page shutdown handler.

## [1.4.0-beta.2] - 2026-10-23

- Default the compose send time to the next local calendar day at 08:00, including across daylight-saving changes. Preserve a date/time already selected by the user.

## [1.4.0-beta.1] - 2026-10-23

Maintained fork preview; original upstream history is preserved.

- CLI-only authenticated per-account SMTP and IMAP, encrypted credentials usable after logout, no PHP mail fallback.
- Recipient parsing, body/attachment isolation, Bcc privacy, actual sending Date and stable Message-ID.
- Serialized worker claims; bounded pre-submission connection retries; held ambiguous failures; Sent-only recovery.
- Atomic, idempotent compose scheduling; owned sender identities; explicit request checks and safe error feedback.
- Native queue menu/count, owner-scoped EML download, guarded mutations and confirmed UI updates.
- Held errors cannot be requeued through the browser. Protected processing/completed/Sent-copy states cannot be deleted.
- PHP/JS and MariaDB regression suites, pinned Roundcube parser source and PHP 8.1–8.5 CI.
- Reviewed all eight existing public forks; documented decisions and operation/upgrade limits.
- Removed unused legacy compose scripts that overrode Roundcube HTTP handling or navigated before confirmation.

## [1.0.0] - 2026-04-11

- Formalized the plugin's self-metadata through `scheduled_sending::PLUGIN_VERSION` and `scheduled_sending::info()`.
- Aligned self-versioning with a cleaner release workflow while keeping existing plugin behavior intact.
