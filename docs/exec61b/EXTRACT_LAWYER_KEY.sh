#!/usr/bin/env bash
#
# Execution 61B — securely export the newly generated LAWYER upload key.
#
# The keystore was generated inside an ephemeral build sandbox, which is NOT a
# durable secret store. Run this ONCE, on the build machine, and move the two
# files it prints to a password manager / vault, then delete them from the
# sandbox. Never commit either file.
#
# It does NOT print the password to stdout; it copies the files to a destination
# you choose and tells you where they are.
#
set -euo pipefail

SRC="/workspace/project/mawatheq-private/signing"
DEST="${1:-}"

if [ -z "$DEST" ]; then
  echo "usage: $0 <destination-directory>" >&2
  echo "  e.g. $0 /mnt/secure/mawatheq-signing" >&2
  exit 2
fi

if [ ! -f "$SRC/mawatheq-lawyer-upload-key.jks" ]; then
  echo "ERROR: $SRC/mawatheq-lawyer-upload-key.jks not found." >&2
  echo "The lawyer key was never generated on this machine." >&2
  exit 1
fi

mkdir -p "$DEST"
chmod 700 "$DEST"
cp "$SRC/mawatheq-lawyer-upload-key.jks" "$DEST/"
cp "$SRC/lawyer-upload-key.pw" "$DEST/"
chmod 600 "$DEST/mawatheq-lawyer-upload-key.jks" "$DEST/lawyer-upload-key.pw"

echo "Exported to $DEST:"
echo "  mawatheq-lawyer-upload-key.jks   (PKCS12 keystore, alias: mawatheq-lawyer-upload)"
echo "  lawyer-upload-key.pw             (store + key password, same value)"
echo
echo "Fingerprint (safe to record, this is a PUBLIC certificate digest):"
PW="$(cat "$SRC/lawyer-upload-key.pw")"
keytool -list -v -keystore "$SRC/mawatheq-lawyer-upload-key.jks" \
  -alias mawatheq-lawyer-upload -storepass "$PW" 2>/dev/null \
  | grep -iE "Alias name|SHA256"
echo
echo "NEXT: move both files into your vault, then remove them from $DEST and $SRC."
