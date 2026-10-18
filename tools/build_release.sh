#!/usr/bin/env bash
# Build the Mawatheq cPanel release artifacts from a git ref.
#
#   bash tools/build_release.sh [ref] [output-dir]
#
# Defaults: ref=HEAD, output-dir=/tmp/mawatheq_release
#
# Lives in tools/ because .htaccess denies that directory: a build script at the web
# root would be downloadable. Same reason tools/production_check.php lives here.
#
# Produces, in the output directory:
#   MAWATHEQ_WEBSITE_CPANEL_FINAL.zip   the deployable site (no secrets, no tests)
#   MAWATHEQ_DB_MIGRATIONS_SAFE.zip     schema, upgrades, repair tooling, deploy guide
#   SHA256SUMS.txt                      checksums in `sha256sum -c` format
#   MANIFEST.txt                        file list and mode summary per archive
#
# Two rules drive the whole script:
#
#   1. The package is built from the ref, not the working tree, so a rebuild of the
#      same commit yields the same bytes. `git archive` supplies the tracked files.
#   2. File modes are normalized on purpose. Apache serves CSS as a user that is not
#      the file owner, so a stylesheet left at 0660 or 0600 returns 403 while every
#      page still reports HTTP 200 — the site loses its styling and nothing looks
#      broken in a status check. Everything is 644, directories 755, except
#      config/secrets.php which is 600 and is never packaged.

set -uo pipefail

REF="${1:-HEAD}"
OUT="${2:-/tmp/mawatheq_release}"
# ROOT is the repository root; this script lives in tools/.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Directories that are not part of a running site. tests/ and docs/ are blocked at
# the web layer anyway, and apps/ is the Flutter client, which is built elsewhere.
# (Listed here for documentation; the extraction step removes them by name.)
# vendor/ is git-ignored but required at runtime (onelogin/php-saml backs the SSO
# routes); it is copied from the working tree rather than the ref.
VENDOR_DIR="$ROOT/vendor"

fail() { printf '\nBUILD FAILED: %s\n' "$*" >&2; exit 1; }
step() { printf '\n== %s\n' "$*"; }

cd "$ROOT" || fail "cannot enter $ROOT"
command -v zip >/dev/null 2>&1 || fail "zip is not installed"
command -v unzip >/dev/null 2>&1 || fail "unzip is not installed"

COMMIT="$(git rev-parse --verify "$REF^{commit}" 2>/dev/null)" || fail "unknown ref: $REF"
SHORT="$(git rev-parse --short "$COMMIT")"
step "building from $REF ($SHORT)"

STAGE="$OUT/stage"
rm -rf "$OUT"
mkdir -p "$STAGE/site" "$STAGE/migrations" "$OUT"

# --- site ------------------------------------------------------------------
step "extracting tracked files"
git archive --format=tar "$COMMIT" | tar -x -C "$STAGE/site" || fail "git archive failed"

# Drop the directories that are not part of a running site.
for dir in tests docs apps; do
    rm -rf "${STAGE:?}/site/$dir"
done

# Runtime dependencies.
if [ -d "$VENDOR_DIR" ]; then
    step "bundling vendor/ (composer runtime dependencies)"
    mkdir -p "$STAGE/site/vendor"
    tar -C "$VENDOR_DIR" -cf - . | tar -x -C "$STAGE/site/vendor"
else
    printf '\nWARNING: vendor/ is absent, so the SSO routes will not load. Run composer install first.\n'
fi

# A secrets file must never travel in an archive: it holds the database password and
# the encryption key, and both the site and the migration package are downloaded.
rm -f "$STAGE/site/config/secrets.php"

# --- migrations ------------------------------------------------------------
step "staging the migration package"
cp -r "$STAGE/site/database" "$STAGE/migrations/database"
mkdir -p "$STAGE/migrations/config"
cp "$STAGE/site/config/secrets.example.php" "$STAGE/migrations/config/" 2>/dev/null \
    || printf 'note: config/secrets.example.php not found\n'
# The migrations package is what the operator deploys from, so it carries the current
# deployment guide, the migration report and the verification runbook. Keep the Execution
# 29 docs too: the 29.1 guide supersedes them but an operator mid-upgrade may still be
# following the earlier one.
for doc in \
    MAWATHEQ_DEPLOYMENT_GUIDE_AR.md \
    MAWATHEQ_EXECUTION_30_DEPLOYMENT_AR.md \
    MAWATHEQ_EXECUTION_30_REPORT.md \
    MAWATHEQ_EXECUTION_29_1_DEPLOYMENT_AR.md \
    MAWATHEQ_EXECUTION_29_1_MIGRATION_REPORT.md \
    MAWATHEQ_EXECUTION_29_1_VERIFICATION_RUNBOOK.md \
    MAWATHEQ_EXECUTION_29_DEPLOYMENT_AR.md \
    MAWATHEQ_EXECUTION_29_REPORT.md
do
    [ -f "$STAGE/site/$doc" ] && cp "$STAGE/site/$doc" "$STAGE/migrations/"
done
# The migration package is applied on the server before the site files, so it must
# carry its own copy of the preflight the operator runs first.
[ -f "$STAGE/migrations/database/repair/00_preflight_check.php" ] \
    || fail "database/repair/00_preflight_check.php is missing from the package"
# And the current deployment guide, since a package without it cannot be followed on
# the server. The Execution 30 guide supersedes 29.1 but both are shipped: an operator
# mid-upgrade may still be following the earlier one.
[ -f "$STAGE/migrations/MAWATHEQ_EXECUTION_30_DEPLOYMENT_AR.md" ] \
    || fail "MAWATHEQ_EXECUTION_30_DEPLOYMENT_AR.md is missing from the migration package"
[ -f "$STAGE/migrations/MAWATHEQ_DEPLOYMENT_GUIDE_AR.md" ] \
    || fail "MAWATHEQ_DEPLOYMENT_GUIDE_AR.md is missing from the migration package"
[ -f "$STAGE/migrations/database/upgrade_v56.sql" ] \
    || fail "database/upgrade_v56.sql is missing from the migration package"
# Execution 31's schema is the last migration in the chain. A package that stops at v56
# would deploy the affiliate code against tables that do not exist.
[ -f "$STAGE/migrations/database/upgrade_v57.sql" ] \
    || fail "database/upgrade_v57.sql is missing from the migration package"

# --- modes and timestamps --------------------------------------------------
step "normalizing file modes and timestamps"
# zip records each file's mtime, so a package built by copying files is not
# reproducible: two builds of the same commit differ. The site tree comes from
# `git archive`, which stamps the commit time; the migration tree is copied, so its
# files carry "now". Every staged path is set to the commit time so a rebuild yields
# the same bytes. This is the SOURCE_DATE_EPOCH convention.
STAMP="$(TZ=UTC git show -s --format=%cd --date=format-local:%Y%m%d%H%M.%S "$COMMIT" 2>/dev/null)"
[ -n "$STAMP" ] || fail "could not read the commit date"
printf '  stamping every path with %s (commit time, UTC)\n' "$STAMP"
stamp_tree() {
    local dir="$1"
    find "$dir" -exec touch -h -t "$STAMP" {} +
}
normalize() {
    local dir="$1"
    find "$dir" -type d -exec chmod 755 {} +
    find "$dir" -type f -exec chmod 644 {} +
    # Restore any executable bit that the ref actually tracks, so a script stays a script.
    while IFS= read -r path; do
        [ -n "$path" ] && [ -f "$dir/$path" ] && chmod 755 "$dir/$path"
    done < <(git ls-tree -r "$COMMIT" | awk '$1=="100755"{print $4}')
    chmod 755 "$dir" 2>/dev/null
    # After modes: chmod changes ctime, not mtime, but touching last keeps the order
    # unambiguous and covers the copied files.
    stamp_tree "$dir"
}
TZ=UTC normalize "$STAGE/site"
TZ=UTC normalize "$STAGE/migrations"

# --- guards ----------------------------------------------------------------
step "running package guards"
guard_fail=0
check_absent() {
    local dir="$1" pattern="$2" label="$3"
    local hits
    hits="$(find "$dir" -path "$pattern" -print 2>/dev/null)"
    if [ -n "$hits" ]; then
        printf '  ! %s\n' "$label"
        printf '%s\n' "$hits" | sed 's/^/      /'
        guard_fail=1
    else
        printf '  ok  %s\n' "$label"
    fi
}
check_absent "$STAGE/site" '*/config/secrets.php' 'no config/secrets.php in the site package'
check_absent "$STAGE/site" '*/.git' 'no .git in the site package'
check_absent "$STAGE/site" '*/tests' 'no tests/ in the site package'
check_absent "$STAGE/site" '*/docs' 'no docs/ in the site package'
check_absent "$STAGE/migrations" '*/config/secrets.php' 'no config/secrets.php in the migration package'

# A mode that is not 644/755 is the failure this script exists to prevent, so it is
# asserted rather than assumed.
bad_modes="$(find "$STAGE" -type f ! -perm 644 ! -perm 755 -printf '%m %p\n' 2>/dev/null)"
if [ -n "$bad_modes" ]; then
    printf '  ! files with a mode other than 644/755:\n%s\n' "$(printf '%s\n' "$bad_modes" | sed 's/^/      /')"
    guard_fail=1
else
    printf '  ok  every packaged file is 644 or 755\n'
fi
bad_dirs="$(find "$STAGE" -type d ! -perm 755 -printf '%m %p\n' 2>/dev/null)"
if [ -n "$bad_dirs" ]; then
    printf '  ! directories with a mode other than 755:\n%s\n' "$(printf '%s\n' "$bad_dirs" | sed 's/^/      /')"
    guard_fail=1
else
    printf '  ok  every packaged directory is 755\n'
fi
[ "$guard_fail" -eq 0 ] || fail "a package guard failed; nothing was written"

# --- archives --------------------------------------------------------------
step "writing archives"
SITE_ZIP="$OUT/MAWATHEQ_WEBSITE_CPANEL_FINAL.zip"
MIG_ZIP="$OUT/MAWATHEQ_DB_MIGRATIONS_SAFE.zip"
# -X keeps only the mode bits we just set, so the archive does not carry the builder's
# uid/gid or platform extras.
(cd "$STAGE/site" && zip -r -X -q "$SITE_ZIP" .) || fail "zip of the site package failed"
(cd "$STAGE/migrations" && zip -r -X -q "$MIG_ZIP" .) || fail "zip of the migration package failed"

# The canonical names above are what tests/execution29_1_release_smoke_test.php unpacks, so
# they are not renamed. The delivery also asks for shorter names; ship both by hardlinking,
# which costs no extra bytes and cannot drift from the canonical file.
SITE_ALT="$OUT/MAWATHEQ_WEBSITE_FINAL.zip"
MIG_ALT="$OUT/MAWATHEQ_DATABASE_MIGRATIONS.zip"
ln -f "$SITE_ZIP" "$SITE_ALT" || cp "$SITE_ZIP" "$SITE_ALT" || fail "could not create $SITE_ALT"
ln -f "$MIG_ZIP" "$MIG_ALT" || cp "$MIG_ZIP" "$MIG_ALT" || fail "could not create $MIG_ALT"

# The deployment guide is a deliverable in its own right, so it sits at the top level next
# to the archives rather than only inside the migration package.
if [ -f "$STAGE/migrations/MAWATHEQ_DEPLOYMENT_GUIDE_AR.md" ]; then
    cp "$STAGE/migrations/MAWATHEQ_DEPLOYMENT_GUIDE_AR.md" "$OUT/MAWATHEQ_DEPLOYMENT_GUIDE_AR.md"
else
    fail "MAWATHEQ_DEPLOYMENT_GUIDE_AR.md is missing; cannot ship it as a deliverable"
fi

step "writing checksums"
# One checksum line per distinct file. The hardlinked aliases are listed too, so a download
# of either name verifies against the same digest.
(
    cd "$OUT" && sha256sum \
        "$(basename "$SITE_ZIP")" \
        "$(basename "$MIG_ZIP")" \
        "$(basename "$SITE_ALT")" \
        "$(basename "$MIG_ALT")" \
        MAWATHEQ_DEPLOYMENT_GUIDE_AR.md \
    > SHA256SUMS.txt
) || fail "sha256sum failed"

# --- manifest --------------------------------------------------------------
{
    printf 'Mawatheq release\n'
    printf 'ref        : %s\n' "$REF"
    printf 'commit     : %s\n' "$COMMIT"
    printf 'built at   : %s\n\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    printf '== %s\n' "$(basename "$SITE_ZIP")"
    printf 'files      : %s\n' "$(find "$STAGE/site" -type f | wc -l)"
    printf 'bytes      : %s\n' "$(du -sb "$STAGE/site" | cut -f1)"
    printf 'modes      : %s\n\n' "$(find "$STAGE/site" -type f -printf '%m\n' | sort -u | tr '\n' ' ')"
    printf '== %s\n' "$(basename "$MIG_ZIP")"
    printf 'files      : %s\n' "$(find "$STAGE/migrations" -type f | wc -l)"
    printf 'bytes      : %s\n' "$(du -sb "$STAGE/migrations" | cut -f1)"
    printf 'modes      : %s\n\n' "$(find "$STAGE/migrations" -type f -printf '%m\n' | sort -u | tr '\n' ' ')"
    printf '== sql files in the site package (must be none outside database/)\n'
    find "$STAGE/site" -name '*.sql' -printf '  %p\n' | sort
} > "$OUT/MANIFEST.txt"

# --- verify ----------------------------------------------------------------
step "verifying the written archives"
VERIFY="$OUT/.verify"
mkdir -p "$VERIFY/site" "$VERIFY/migrations"
unzip -q "$SITE_ZIP" -d "$VERIFY/site" || fail "the site archive does not unpack"
unzip -q "$MIG_ZIP" -d "$VERIFY/migrations" || fail "the migration archive does not unpack"
[ -f "$VERIFY/site/index.php" ] || fail "the site archive has no index.php at its root"
[ -f "$VERIFY/site/.htaccess" ] || fail "the site archive has no .htaccess"
[ -f "$VERIFY/migrations/database/schema.sql" ] || fail "the migration archive has no schema.sql"
if find "$VERIFY" -name 'secrets.php' -print -quit | grep -q .; then
    fail "a secrets.php reached an archive"
fi
bad="$(find "$VERIFY" -type f ! -perm 644 ! -perm 755 -printf '%m %p\n')"
[ -z "$bad" ] || fail "an archive unpacked with a non-644/755 file mode:\n$bad"
(cd "$OUT" && sha256sum -c SHA256SUMS.txt --quiet) || fail "the checksums do not verify"
rm -rf "$VERIFY"

step "done"
printf 'site package     : %s (%s)\n' "$SITE_ZIP" "$(du -h "$SITE_ZIP" | cut -f1)"
printf 'migration package: %s (%s)\n' "$MIG_ZIP" "$(du -h "$MIG_ZIP" | cut -f1)"
printf 'checksums        : %s\n' "$OUT/SHA256SUMS.txt"
printf 'manifest         : %s\n' "$OUT/MANIFEST.txt"
printf '\nVerify with: (cd %s && sha256sum -c SHA256SUMS.txt)\n\n' "$OUT"
