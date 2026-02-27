#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$ROOT_DIR"

run_step() {
  local label="$1"
  shift
  echo "==> ${label}"
  "$@"
  echo "OK: ${label}"
  echo
}

run_step "Full System Audit" php database/full_system_audit.php
run_step "Ledger Integrity Check" php database/ledger_integrity_check.php
run_step "Regression CRUD + Snapshot Runner" php database/run_regression_tests.php

echo "CI audit suite passed."

