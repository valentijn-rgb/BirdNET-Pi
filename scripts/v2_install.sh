#!/usr/bin/env bash
# v2 web UI install step (run as root, idempotent). Called from update_birdnet_snippets.sh.
# V2_DB overrides the database path (used by the emulator tests to work on a copy).
set -e
source /etc/birdnet/birdnet.conf
if [ -n "${BIRDNET_USER}" ]; then
  USER=${BIRDNET_USER}
  HOME=/home/${BIRDNET_USER}
else
  USER=$(awk -F: '/1000/ {print $1}' /etc/passwd)
  HOME=$(awk -F: '/1000/ {print $6}' /etc/passwd)
fi
DB=${V2_DB:-$HOME/BirdNET-Pi/scripts/birds.db}

# 1. Index for per-species and per-day queries. Takes a write lock for ~1-2 s on 50k rows;
#    the analysis writer retries for ~21 s (reporting.py), so it survives.
sqlite3 "$DB" 'CREATE INDEX IF NOT EXISTS "detections_Sci_Name_Date_Time" ON "detections" ("Sci_Name","Date","Time");'

# 2. Serve the v2 pages from the web root.
sudo -u "$USER" ln -sfn "$HOME/BirdNET-Pi/homepage/v2" "$EXTRACTED/v2"

# 3. Dutch/English names for the v2 pages (git-ignored, regenerated on every update).
sudo -u "$USER" php "$HOME/BirdNET-Pi/scripts/v2_make_labels.php"
