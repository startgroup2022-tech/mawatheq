#!/usr/bin/env bash
# Run every PHP suite and HTTP probe against the local test environment.
#
#   bash tests/run_all_tests.sh            # everything
#   bash tests/run_all_tests.sh execution29   # only suites whose name matches
#
# Prerequisites: `bash tests/setup_test_env.sh` has run, Apache is listening on
# 127.0.0.1:8080 (the probes hardcode it), and the mock AI provider is up on 11434.
#
# Why this exists rather than a plain `for` loop over tests/*.php: the suites log in
# over real HTTP, and the application rate-limits repeated logins per account+IP. Run
# back to back, a later suite inherits the throttle from an earlier one and reports
# login failures that have nothing to do with the code — for example
# execution29_file_security_probe sees the owner's download answered with a 302 to the
# login page. The throttle row is cleared before each suite so a failure means a
# failure.

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FILTER="${1:-}"
cd "$ROOT" || exit 1

DB_NAME="${MAWATHEQ_DB_NAME:-mawatheq_test}"
MARIADB="mariadb"
sudo -n true 2>/dev/null && MARIADB="sudo mariadb"

clear_throttle() {
    $MARIADB "$DB_NAME" -e "DELETE FROM auth_rate_limits;" >/dev/null 2>&1
}

# Apache writes consultation attachments as www-data under a group-only private root
# (see setup_test_env.sh). This shell's supplementary groups are fixed at login, so a user
# added to www-data afterwards reads those files with "Permission denied" and
# execution30_consultation_attachments_probe fails on a file it is entitled to read.
# Re-entering the group per suite keeps the run self-consistent.
# Membership is read from the group database, not from `id -nG`: this shell's supplementary
# groups were fixed when it logged in, so a user added to www-data by setup_test_env.sh does
# not show the group here even though `sg` can still enter it.
if command -v sg >/dev/null 2>&1 && getent group www-data 2>/dev/null | grep -qw "$(id -un)"; then
    run_suite() { sg www-data -c "php $(printf '%q' "$1")"; }
else
    run_suite() { php "$1"; }
fi

# The mock provider backs execution12_ai_connectivity_test. Start it if absent rather
# than reporting three failures for a server that is simply not running.
if ! ss -lnt 2>/dev/null | grep -q ':11434 '; then
    if [ -f /tmp/mockai/router.php ]; then
        nohup php -S 127.0.0.1:11434 /tmp/mockai/router.php >/tmp/mockai/server.log 2>&1 &
        sleep 1
        printf 'note: started the mock AI provider on 11434\n'
    else
        printf 'note: mock AI provider absent (/tmp/mockai/router.php); execution12 will report failures\n'
    fi
fi

if ! curl -s -o /dev/null -m 5 http://127.0.0.1:8080/index.php; then
    printf 'ERROR: no app on 127.0.0.1:8080. Run: bash tests/setup_test_env.sh\n' >&2
    exit 2
fi

# Seed the business fixtures the HTTP probes log in with. executive08_http_probe
# authenticates as client-firm<N>@example.com, which only seed_phase6_business.php
# creates; without it the probe fails at "client 1 (firm A) logs in" and every later
# check in that file cascades. The seeds are idempotent, so running them is safe on a
# database that already has the rows.
for seed in seed_phase6.php seed_phase6_business.php seed_crm_demo.php; do
    if [ -f "tests/$seed" ]; then
        php "tests/$seed" >/dev/null 2>&1 || printf 'note: %s reported an error\n' "$seed"
    fi
done

pass=0; fail=0; failed_names=()
printf '\n%-44s %s\n' 'SUITE' 'RESULT'
printf '%s\n' "------------------------------------------------------------"

for file in tests/*_test.php tests/*_probe.php; do
    [ -e "$file" ] || continue
    name="$(basename "$file" .php)"
    if [ -n "$FILTER" ] && [[ "$name" != *"$FILTER"* ]]; then
        continue
    fi

    clear_throttle
    out="$(run_suite "$file" 2>&1)"
    rc=$?
    summary="$(printf '%s' "$out" | grep -iE 'checks|checks passed|failure' | tail -1)"
    if [ "$rc" -eq 0 ]; then
        pass=$((pass + 1))
        printf '%-44s PASS  %s\n' "$name" "$summary"
    else
        fail=$((fail + 1))
        failed_names+=("$name")
        printf '%-44s FAIL  rc=%s  %s\n' "$name" "$rc" "$summary"
        printf '%s\n' "$out" | grep -iE 'FAIL|Error|Uncaught|SQLSTATE' | head -8 | sed 's/^/        /'
    fi
done

printf '%s\n' "------------------------------------------------------------"
printf 'suites passed: %s   failed: %s\n' "$pass" "$fail"
if [ "$fail" -gt 0 ]; then
    printf 'failed: %s\n' "${failed_names[*]}"
    exit 1
fi
printf 'all suites passed\n'
