#!/usr/bin/env bash
# Downloads the BirdNET+ V3.0 preview model (70 MB, not in git) and checks its SHA-256.
# MODEL_DIR and MODEL_URL can be overridden (for testing).
set -euo pipefail

NAME=BirdNET_V3.0-preview3.1_Global_11K_FP16_pruned
SHA256=f4af6290690e0031cd785d4c810167af83467ce1426f4eba249ca994681000db
MODEL_URL=${MODEL_URL:-"https://zenodo.org/records/20703646/files/BirdNET+_V3.0-preview3.1_Global_11K_FP16_pruned.tflite"}
MODEL_DIR=${MODEL_DIR:-"$HOME/BirdNET-Pi/model"}

target="$MODEL_DIR/$NAME.tflite"
sha_ok() { [ "$(sha256sum "$1" | cut -d' ' -f1)" = "$SHA256" ]; }
part="$target.part"

if [ -f "$target" ] && sha_ok "$target"; then
  echo "$NAME already installed, checksum OK"
  exit 0
fi

trap 'rm -f "$part"' EXIT
curl -sSLf --retry 3 -o "$part" "$MODEL_URL"
if ! sha_ok "$part"; then
  echo "checksum mismatch for $MODEL_URL" >&2
  exit 1
fi
mv "$part" "$target"
echo "$NAME installed, checksum OK"
