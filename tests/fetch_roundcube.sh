#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p .cache
archive=.cache/roundcube.tar.gz
curl --fail --location --retry 3 --output "$archive" https://codeload.github.com/roundcube/roundcubemail/tar.gz/29beae7282135a3cd59a3945bd33ee8f904d6217
expected=ec2b9d60e64f17d5e6a9853612c06d67e10ddeda0ec9f4262fb9e5129314a757
actual=$(php -r 'echo hash_file("sha256", $argv[1]);' "$archive")
if [[ "$actual" != "$expected" ]]; then echo 'Roundcube source checksum mismatch' >&2; exit 1; fi
mkdir -p .cache/roundcube
tar -xzf "$archive" -C .cache/roundcube --strip-components=1

# The same official PEAR Mail_Mime release used by the supported installation.
mime_archive=.cache/mail-mime.tar.gz
curl --fail --location --retry 3 --output "$mime_archive" https://codeload.github.com/pear/Mail_Mime/tar.gz/cda56082cc1a3722d6929caa66abfe95b7406e12
mime_expected=647edf663dd198b7e7d2efe294b3d853d256cf8065f30557d56f7b12c5847e00
mime_actual=$(php -r 'echo hash_file("sha256", $argv[1]);' "$mime_archive")
if [[ "$mime_actual" != "$mime_expected" ]]; then echo 'Mail_Mime source checksum mismatch' >&2; exit 1; fi
mkdir -p .cache/mail-mime .cache/roundcube/program/lib/Mail
tar -xzf "$mime_archive" -C .cache/mail-mime --strip-components=1
cp .cache/mail-mime/Mail/mimePart.php .cache/roundcube/program/lib/Mail/mimePart.php
