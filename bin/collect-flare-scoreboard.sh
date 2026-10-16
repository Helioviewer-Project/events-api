#!/usr/bin/env bash
# Collect the full history of every enabled Flare Scoreboard model, each from
# its own first date at CCMC to its last (or today), in CHUNK-day chunks.
# Dry run by default: lists each model's dates and the collect it would run.
#   make collect-flare-scoreboard                                    # dry run
#   make collect-flare-scoreboard APPLY=1                            # collect every enabled model
#   make collect-flare-scoreboard APPLY=1 DATASETS="ASSA_1_REGIONS,DAFFS_REGIONS"
#   ENV=production make collect-flare-scoreboard APPLY=1 CHUNK=30
set -euo pipefail
cd "$(dirname "$0")/.."

# collect.php exits cleanly on Ctrl-C, so stop here too instead of moving on to the next model
trap 'echo; echo "Interrupted: stopped before the remaining models." >&2; exit 130' INT TERM

HAPI=https://ccmc.gsfc.nasa.gov/flare-scoreboard/hapi
CHUNK=${CHUNK:-30}
TODAY=$(date -u +%F)
APPLY=${APPLY:-}

# Registered sources; names can contain spaces (Met Office), the path follows 2+ spaces
mapfile -t SOURCES < <(make -s sources 2>/dev/null | sed -nE 's/^(FLARE_SCOREBOARD_.*[^ ]) {2,}CCMC>>.*/\1/p')
if [ ${#SOURCES[@]} -eq 0 ]; then
    echo "No Flare Scoreboard source is enabled (see the model list in src/Events/Collector.php)." >&2
    exit 1
fi

IFS=',' read -r -a WANTED <<< "${DATASETS:-}"

for source in "${SOURCES[@]}"; do
    dataset=${source#FLARE_SCOREBOARD_}
    if [ ${#WANTED[@]} -gt 0 ] && ! printf '%s\n' "${WANTED[@]}" | grep -qxF "$dataset"; then
        continue
    fi

    info=$(curl -sfG "$HAPI/info" --data-urlencode "dataset=$dataset") || { echo "!! $dataset: CCMC info failed, skipped" >&2; continue; }
    start=$(jq -r '.startDate[0:10]' <<< "$info")
    stop=$(jq -r '.stopDate[0:10]' <<< "$info")
    [[ "$stop" > "$TODAY" ]] && stop=$TODAY

    if [ -z "$APPLY" ]; then
        printf '%-42s %s → %s  (%s days)\n' "$dataset" "$start" "$stop" $(( ($(date -ud "$stop" +%s) - $(date -ud "$start" +%s)) / 86400 + 1 ))
        printf '    make collect %s %s %s SOURCES="%s"\n' "$start" "$stop" "$CHUNK" "$source"
        continue
    fi

    echo "== $dataset: $start → $stop in ${CHUNK}-day chunks"
    make collect "$start" "$stop" "$CHUNK" SOURCES="$source"
done
[ -z "$APPLY" ] && echo "DRY RUN: nothing collected. Re-run with APPLY=1 to collect."
true
