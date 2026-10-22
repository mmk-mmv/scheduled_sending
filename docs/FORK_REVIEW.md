# Review of the eight existing public forks

Reviewed 2026-10-23 against upstream [`b25f268f0b23e91f9b27e48ef1cdfeecfba53ec8`](https://github.com/texxasrulez/scheduled_sending/commit/b25f268f0b23e91f9b27e48ef1cdfeecfba53ec8). Heads were fetched locally and compared with `git rev-list --left-right --count upstream...fork` plus commit and source diffs. Ahead/behind describes graph position, not quality or how many fixes remain useful. No fork was installed into production for this review.

| Fork / exact reviewed head | Ahead | Behind | Findings and decision |
| --- | ---: | ---: | --- |
| [MiMoHo/texxasrulez-scheduled_sending](https://github.com/MiMoHo/texxasrulez-scheduled_sending) · [`d20749c52c48`](https://github.com/MiMoHo/texxasrulez-scheduled_sending/commit/d20749c52c4872e8d98047dda070788d35f0dc06) | 1 | 0 | TLS verification and token in HTTP header. Addresses an HTTP-helper weakness; this fork uses a local CLI-only worker and has no HTTP token to transmit. |
| [Carabas/scheduled_sending](https://github.com/Carabas/scheduled_sending) · [`25132e9e1b67`](https://github.com/Carabas/scheduled_sending/commit/25132e9e1b67621662e7bf4803cab6a828753d00) | 2 | 0 | Initial scheduling suggestion shortened to one minute. Preference change, not a delivery fix; retained 30-minute suggestion. |
| [B0F1B0/scheduled_sending](https://github.com/B0F1B0/scheduled_sending) · [`9b090a615dd1`](https://github.com/B0F1B0/scheduled_sending/commit/9b090a615dd163fa95f0f568b095bfd3e9361277) | 0 | 4 | No unique commits versus upstream. |
| [damkh/scheduled_sending](https://github.com/damkh/scheduled_sending) · [`ba387e45a910`](https://github.com/damkh/scheduled_sending/commit/ba387e45a910c08c71d678cf4fcd89ad716fcaab) | 33 | 8 | 33 unique commits: MIME/recipients, dates, draft/Sent handling, queue navigation/badge, preview and documentation. Useful UI ideas independently implemented with stricter ownership/state handling; no blanket merge. |
| [koenr/scheduled_sending](https://github.com/koenr/scheduled_sending) · [`45e0e773b35e`](https://github.com/koenr/scheduled_sending/commit/45e0e773b35e8c4debc8def098d99da8517ef14f) | 0 | 13 | No unique commits versus upstream. |
| [facoltativos/scheduled_sending](https://github.com/facoltativos/scheduled_sending) · [`311311ab91c9`](https://github.com/facoltativos/scheduled_sending/commit/311311ab91c9b25993c5da014a8b7036f748cd6c) | 4 | 18 | CRLF in minimal MIME, Date at actual delivery, compose selectors/layout. MIME and Date behavior already covered by the maintained worker. |
| [firstred/scheduled_sending](https://github.com/firstred/scheduled_sending) · [`2de5c4fff216`](https://github.com/firstred/scheduled_sending/commit/2de5c4fff2165278d851a16b11a6d0b220068b1d) | 0 | 19 | No unique commits versus upstream. |
| [poremland/scheduled_sending](https://github.com/poremland/scheduled_sending) · [`ee5c3910cf44`](https://github.com/poremland/scheduled_sending/commit/ee5c3910cf44523cd522281c0a6ede9246c014e6) | 0 | 34 | No unique commits versus upstream. |

## Why the largest fork was not merged wholesale

At the reviewed damkh head, the cancel and reschedule SQL handlers do not consistently bind the job ID to the logged-in user. Reschedule can also requeue states without the guarded state set used here. Those handlers lack explicit local request validation; Roundcube's outer request handling was not penetration-tested, so this observation is not a claim of a demonstrated CSRF exploit.

Its worker retains a PHP `mail()` fallback, calls the SMTP send path without the explicit authenticated connection used here, and puts failed/uncertain delivery back into the queue. Subject-based draft recovery can also select an unrelated draft. These are reasons to preserve our delivery implementation and test the queue operations independently, rather than treating 33 commits as proof of reliability.

The useful queue-menu/badge idea was adopted through an independent implementation. EML download offers message inspection without introducing an in-origin HTML preview renderer. SQL integration tests exercise another owner's IDs, protected states, bad request tokens, duplicate requests and failed commits against MariaDB.

## Remaining limits

Tests simulate SMTP/IMAP, including failure after a send attempt, logout, account changes and Sent-copy recovery; they send no mail. Real server/provider behavior, custom skins and attachment storage backends still need isolated integration validation. No implementation can guarantee exactly-once SMTP delivery after every possible crash.

## Archival and license

GitHub states the original repository was archived by its owner on **2026-10-14**. No explanation was found in its README, visible commit history or the 17 public issues/pull requests inspected. The reason is unknown; archival alone does not demonstrate a security problem or a replacement project.

The repository includes the full [GNU GPL version 3](../LICENSE). A public, modified source fork is allowed under its conditions: retain notices/license, identify modifications and dates, and distribute corresponding source under the same license. Original authorship and history remain credited.
