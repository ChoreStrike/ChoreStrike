#!/bin/bash
# Run from Git Bash/WSL or a POSIX shell; the portable runner owns no teardown.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec node "$SCRIPT_DIR/run-tests.js" "$@"
