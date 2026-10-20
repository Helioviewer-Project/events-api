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

# Registered sources and their paths; names can contain spaces (Met Office), the path follows 2+ spaces
declare -A PATHS
while IFS=$'\t' read -r name path; do
    PATHS[$name]=$path
done < <(make -s sources 2>/dev/null | sed -nE 's/^(FLARE_SCOREBOARD_.*[^ ]) {2,}(CCMC>>.*[^ ]) *$/\1\t\2/p')
mapfile -t SOURCES < <(make -s sources 2>/dev/null | sed -nE 's/^(FLARE_SCOREBOARD_.*[^ ]) {2,}CCMC>>.*/\1/p')

# What we already hold, per path: events, first and last start date (one query)
declare -A OURS OURS_LINE
days() { echo $(( ($(date -ud "$2" +%s) - $(date -ud "$1" +%s)) / 86400 + 1 )); }
while IFS='|' read -r path count first last; do
    OURS[$path]="$count events, $first → $last"
    OURS_LINE[$path]="$first → $last  ($(days "$first" "$last") days)  ($count events)"
done < <(printf '%s\n' '\pset format unaligned' '\pset tuples_only on' '\pset fieldsep |' \
    "select path, count(*), min(to_timestamp(start))::date, max(to_timestamp(start))::date from events where path like 'CCMC>>Solar Flare Predictions>>%' group by path;" \
    | make -s db-shell 2>/dev/null | grep -E '^CCMC>>[^|]+\|[0-9]+\|')
if [ ${#SOURCES[@]} -eq 0 ]; then
    echo "No Flare Scoreboard source is enabled (see the model list in src/Events/Collector.php)." >&2
    exit 1
fi

IFS=',' read -r -a WANTED <<< "${DATASETS:-}"

echo "Now: $(date -u '+%Y-%m-%d %H:%M:%S') UTC"

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
        printf '%-42s %s → %s  (%s days)\n' "$dataset" "$start" "$stop" "$(days "$start" "$stop")"
        printf '%-42s %s\n' '' "${OURS_LINE[${PATHS[$source]:-}]:-none in our database}"
        printf '    make collect %s %s %s SOURCES="%s"\n' "$start" "$stop" "$CHUNK" "$source"
        continue
    fi

    echo "== $(date -u '+%Y-%m-%d %H:%M:%S') UTC | $dataset: $start → $stop in ${CHUNK}-day chunks (ours: ${OURS[${PATHS[$source]:-}]:-none})"
    make collect "$start" "$stop" "$CHUNK" SOURCES="$source"
done
[ -z "$APPLY" ] && echo "DRY RUN: nothing collected. Re-run with APPLY=1 to collect."
true
