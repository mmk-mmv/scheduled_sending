# Contributing

Preserve GPL-3.0 notices and original authorship. Explain a concrete problem, resulting behavior and validation. Add regression tests for delivery, ownership, state transitions and browser error handling. Run the commands in README; changes to SQL need the isolated MariaDB suite as well.

Never use a production mailbox or send test mail as part of this suite. Stub all message transports. Use synthetic example.org/example.net data and never commit local configuration, queue dumps, message files or credentials. Avoid a change that introduces automatic retries after potentially accepted SMTP delivery.

Pin CI actions and external parser source revisions; verify downloaded archive checksums. Describe compatibility limits honestly. Changes affecting a custom skin or attachment backend need an isolated browser/integration check before claiming support.
