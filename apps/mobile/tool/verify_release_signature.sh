#!/usr/bin/env bash
# Report what actually signed a built APK/AAB, and whether it can go to Google Play.
#
#   bash apps/mobile/tool/verify_release_signature.sh [path/to/app-release.apk]
#
# Exits 1 when the artifact is debug-signed, because that is a blocker for a Play upload.
# This deliberately inspects the built bytes: reading key.properties would only prove what
# we *intended* to sign with.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
APK="${1:-$ROOT/apps/mobile/build/app/outputs/flutter-apk/app-release.apk}"
AAB="${AAB:-$ROOT/apps/mobile/build/app/outputs/bundle/release/app-release.aab}"

SDK="${ANDROID_HOME:-${ANDROID_SDK_ROOT:-/workspace/android-sdk}}"
APKSIGNER="$(ls -1 "$SDK"/build-tools/*/apksigner 2>/dev/null | sort -V | tail -1)"
AAPT="$(ls -1 "$SDK"/build-tools/*/aapt2 2>/dev/null | sort -V | tail -1)"

fail=0

echo "== artifact =="
if [[ -f "$APK" ]]; then
    echo "APK: $APK ($(stat -c%s "$APK") bytes)"
else
    echo "APK: MISSING at $APK"
    fail=1
fi

if [[ -n "$AAPT" && -f "$APK" ]]; then
    echo
    echo "== identity (must not change once published) =="
    "$AAPT" dump badging "$APK" 2>/dev/null | grep -E "^package:|^sdkVersion|^targetSdkVersion" | sed 's/^/  /'
fi

if [[ -f "$APK" ]]; then
    echo
    echo "== signature =="
    # apksigner is a shell wrapper around `java`. Without a JDK it exits 0 having done
    # nothing, which would read as "not the debug key" — a check that cannot fail. Require
    # the tool to actually run and require a DN to be printed before judging anything.
    if [[ -z "$APKSIGNER" ]]; then
        echo "  BLOCKER: apksigner not found under $SDK/build-tools."
        fail=1
    elif ! command -v java >/dev/null 2>&1 && [[ -z "${JAVA_HOME:-}" ]]; then
        echo "  BLOCKER: no java on PATH and JAVA_HOME unset; apksigner cannot run."
        fail=1
    else
        "$APKSIGNER" verify --print-certs --verbose "$APK" 2>&1 \
            | grep -iE "verified|Signer #1 certificate DN|scheme" | sed 's/^/  /'
        dn="$("$APKSIGNER" verify --print-certs "$APK" 2>/dev/null | grep -i 'certificate DN' | head -1)"
        if [[ -z "$dn" ]]; then
            echo "  BLOCKER: could not read a certificate DN; signature NOT verified."
            fail=1
        elif grep -qi "CN=Android Debug" <<<"$dn"; then
            echo "  BLOCKER: signed with the debug key. Google Play will reject it."
            echo "  Put the owner's upload key at android/key.properties (see key.properties.example)."
            fail=1
        else
            echo "  OK: signed by $(sed 's/.*DN: *//' <<<"$dn")"
        fi
    fi
fi

if [[ -f "$AAB" ]]; then
    echo
    echo "== bundle =="
    echo "  AAB: $AAB ($(stat -c%s "$AAB") bytes)"
    if command -v jarsigner >/dev/null 2>&1; then
        jarsigner -verify "$AAB" >/dev/null 2>&1 \
            && echo "  jarsigner: verified" \
            || echo "  jarsigner: NOT verified"
    fi
fi

echo
if [[ "$fail" -eq 0 ]]; then
    echo "RESULT: ready for a Play upload."
else
    echo "RESULT: NOT ready for a Play upload (see BLOCKER above)."
fi
exit "$fail"
