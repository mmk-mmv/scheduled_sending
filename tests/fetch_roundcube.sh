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
