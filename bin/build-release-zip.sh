#!/usr/bin/env bash
#
# bin/build-release-zip.sh — assemble the release ZIP (PRO-2470 release train).
#
# This is the ONE owner of what a shipped package contains: the release
# workflow (.github/workflows/release.yaml) and the packaging check
# (bin/verify-release-zip.sh) both call it, so the artifact CI publishes and
# the artifact the check inspects can never drift apart.
#
# Usage:
#   bin/build-release-zip.sh [output.zip]      (default: ./smaily-connect-magento2.zip)
#
# What stays out: the development apparatus (tests, CI, sandbox, tooling,
# static-analysis config), the Hyvä companion (compat/, published as the
# separate package smaily/module-connect-hyva) and the internal working
# documents. docs/ ships — the user guide, the architecture notes and the
# engine contract are part of the deliverable.

set -euo pipefail

ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT="${1:-smaily-connect-magento2.zip}"
case "$OUT" in
    /*) ;;
    *) OUT="$PWD/$OUT" ;;
esac

rm -f "$OUT"
cd "$ROOT"

zip -q -r -X "$OUT" . \
    -x '.git/*' '.github/*' '.sandbox/*' '.vscode/*' '.claude/*' \
       'Test/*' 'compat/*' 'vendor/*' 'var/*' 'bin/*' \
       'docker-compose.yaml' 'Dockerfile' \
       'phpcs.xml.dist' 'phpstan.neon.dist' \
       'phpunit.xml.dist' 'phpunit.integration.xml.dist' \
       'composer.lock' '.gitignore' \
       'CONTRIBUTING.md' 'CLAUDE.md' 'STATUS.md' 'BACKLOG.md' \
       '*.zip' '*.zip.sha256'

echo "$OUT"
