# Credits and provenance

- **Gene Hawkins / texxasrulez**: original Scheduled Sending plugin, GPL-3.0 license, retained upstream history and inherited UI/translations/assets.
- **Scheduled Sending contributors**: maintained fork, 2026-10-23 delivery/session fixes, queue authorization, compose idempotency, transaction handling, diagnostics, documentation and regression/CI work.
- **damkh**: reviewed queue-navigation/badge, preview, MIME/recipient/date and user-documentation work. This fork independently implements queue navigation/badge with owner-scoped operations and attachment-only EML inspection; it does not merge the alternate worker or mutation handlers.
- **MiMoHo**: reviewed TLS verification and header-based HTTP worker token fix. This fork removes the HTTP-worker dependency entirely.
- **facoltativos**: reviewed MIME newline, actual sending-date and compose-layout improvements, covered by this implementation's MIME/Date handling.
- **Carabas**: reviewed shorter default scheduling suggestion; this fork defaults to the next local 08:00 (today before 08:00, otherwise tomorrow).
- **B0F1B0, koenr, firstred, poremland**: reviewed fork heads; no commits unique to those forks relative to current upstream.
- **Roundcube contributors**: official parser/input libraries used in tests; source is downloaded separately from a pinned official commit, not vendored into this plugin.

Exact reviewed revisions and adoption decisions: [fork review](docs/FORK_REVIEW.md). No affiliation or endorsement is implied.
