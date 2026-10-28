#!/usr/bin/env bash
# v2 web UI install step (run as root, idempotent). Called from update_birdnet_snippets.sh.
# V2_DB overrides the database path (used by the emulator tests to work on a copy).
# Each step runs on its own (set -e inside it): a failing step is reported as "v2: <step> failed"
# and the others still run, so /v2 is never left missing; the exit status is 1 if any failed.
# The cheap steps that make /v2 work come first, the database index after them.
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
set +e

failed=0
# step <name> <command...>: runs the command in a subshell with set -e; a failure is reported and
# the script goes on.
step() {
  local name=$1
  shift
  ( set -e; "$@" )
  if [ $? -ne 0 ]; then
    echo "v2: $name failed" >&2
    failed=1
  fi
}

# 1. Persistent state for the v2 pages (Sessies: facts of finished boots, kept across reboots).
STATE_DIR=${V2_STATE_DIR:-/var/lib/birds-v2}
step "state dir" install -d -o caddy -g caddy -m 0700 "$STATE_DIR"

# 2. Serve the v2 pages from the web root.
step "web-root symlink" sudo -u "$USER" ln -sfn "$HOME/BirdNET-Pi/homepage/v2" "$EXTRACTED/v2"

# 3. Dutch/English names for the v2 pages (git-ignored, regenerated on every update).
step "labels" sudo -u "$USER" php "$HOME/BirdNET-Pi/scripts/v2_make_labels.php"

# 4. Index for per-species and per-day queries. Takes a write lock for ~1-2 s on 50k rows;
#    the analysis writer retries for ~21 s (reporting.py), so it survives. The busy timeout lets this
#    statement wait for a writer instead of failing on a transient lock.
step "index" sqlite3 -cmd '.timeout 30000' "$DB" 'CREATE INDEX IF NOT EXISTS "detections_Sci_Name_Date_Time" ON "detections" ("Sci_Name","Date","Time");'

# 5. Persistent journal + journal read access for caddy (Sessies page). Pi config change, opt-in.
journal() {
  JDROP_DIR=${V2_JOURNALD_DIR:-/etc/systemd/journald.conf.d}
  JDIR=${V2_JOURNAL_DIR:-/var/log/journal}
  JDROP="$JDROP_DIR/birds-persistent.conf"
  if [ "${V2_PERSIST_JOURNAL:-}" = yes ] || [ -f "$JDROP" ]; then
    want=$'[Journal]\nStorage=persistent\nSystemMaxUse=50M'
    if [ "$(cat "$JDROP" 2>/dev/null)" != "$want" ] || [ ! -d "$JDIR" ]; then
      mkdir -p "$JDROP_DIR" "$JDIR"
      printf '%s\n' "$want" > "$JDROP"
      systemd-tmpfiles --create --prefix "$JDIR" 2>/dev/null || true
      systemctl restart systemd-journald
      journalctl --flush 2>/dev/null || true
      echo "v2: journald restarted"
    fi
    # PHP-FPM runs as caddy; reading the system journal needs this group. Trade-off: caddy can read
    # all system logs (fine on a single-purpose LAN device; caddy already has NOPASSWD sudo).
    if ! id -nG caddy | tr ' ' '\n' | grep -qx systemd-journal; then
      usermod -aG systemd-journal caddy
      # Reload, not restart: new workers pick up the group, and a web-UI Update running inside
      # php-fpm's cgroup (this script, via update_birdnet.sh) is not killed.
      systemctl reload 'php*-fpm.service'
      echo "v2: php-fpm reloaded"
    fi
  fi
}
step "journal" journal

exit $failed
