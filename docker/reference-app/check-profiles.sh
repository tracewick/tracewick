#!/bin/sh
# Checks that the profiles of the reference app reach Pyroscope with their labels: sends requests
# to both variants, then asks Pyroscope for the routes it has seen for each runtime and for the
# frames of one route. Asserts wall-time units (nanoseconds), non-empty stacks, and that series
# labels do not look like raw request URLs. Run it after `make app-up`.
#
# Both FPM and RoadRunner write the spool; app-drain delivers to Pyroscope.
set -eu

pyroscope=${PYROSCOPE_URL:-http://127.0.0.1:4040}
requests=${REQUESTS:-100}
timeout=${TIMEOUT:-90}

query() {
    curl -fsS -H 'Content-Type: application/json' -d "$2" "$pyroscope/querier.v1.QuerierService/$1"
}

# Fail if any JSON string value looks like an absolute URL or an HTTP path with a query string.
assert_no_raw_urls() {
    label=$1
    payload=$2
    if echo "$payload" | grep -Eiq '"[^"]*https?://[^"]*"'; then
        echo "$label contains an http(s) URL in a series label: $payload" | cut -c 1-2000 >&2
        exit 1
    fi
    if echo "$payload" | grep -Eq '"[^"]*/[^"]*\?[^"]*"'; then
        echo "$label contains a path with a query string in a series label: $payload" | cut -c 1-2000 >&2
        exit 1
    fi
}

start_ms=$(( $(date +%s) * 1000 - 600000 ))

for base in http://127.0.0.1:8081 http://127.0.0.1:8082; do
    echo "--> Sending $requests requests to each of two routes on $base"
    i=0
    while [ "$i" -lt "$requests" ]; do
        curl -fsS -o /dev/null "$base/en/blog/"
        curl -fsS -o /dev/null "$base/reference/external"
        i=$((i + 1))
    done
done

for runtime in fpm roadrunner; do
    selector="{service_name=\\\"symfony-demo\\\",runtime=\\\"$runtime\\\"}"
    echo "--> Pyroscope has profiles of both routes from $runtime"
    deadline=$(( $(date +%s) + timeout ))
    while :; do
        end_ms=$(( $(date +%s) * 1000 + 60000 ))
        routes=$(query LabelValues "{\"name\":\"route\",\"matchers\":[\"$selector\"],\"start\":$start_ms,\"end\":$end_ms}")
        if echo "$routes" | grep -q '"blog_index"' && echo "$routes" | grep -q '"reference_external"'; then
            echo "$routes"
            assert_no_raw_urls "route LabelValues ($runtime)" "$routes"
            break
        fi
        if [ "$(date +%s)" -ge "$deadline" ]; then
            echo "Pyroscope did not get the expected routes within ${timeout}s: $routes" >&2
            exit 1
        fi
        sleep 2
    done
done

end_ms=$(( $(date +%s) * 1000 + 60000 ))
types=$(query ProfileTypes "{\"start\":$start_ms,\"end\":$end_ms}")
# Push API / pprof wall profiles must advertise nanosecond units in the type id.
type=$(echo "$types" | grep -o '"ID":"[^"]*:wall:nanoseconds:[^"]*"' | head -n 1 | cut -d '"' -f 4)
if [ -z "$type" ]; then
    echo "Pyroscope lists no wall-time profile type in nanoseconds: $types" >&2
    exit 1
fi
echo "--> Profile type (units=nanoseconds): $type"
case "$type" in
    *:wall:nanoseconds:*) ;;
    *)
        echo "Profile type id is not wall/nanoseconds: $type" >&2
        exit 1
        ;;
esac

# Series label names must stay coarse (route/runtime/service), never a raw URL key.
for runtime in fpm roadrunner; do
    selector="{service_name=\\\"symfony-demo\\\",runtime=\\\"$runtime\\\"}"
    names=$(query LabelNames "{\"matchers\":[\"$selector\"],\"start\":$start_ms,\"end\":$end_ms}")
    assert_no_raw_urls "LabelNames ($runtime)" "$names"
    if ! echo "$names" | grep -q '"route"'; then
        echo "LabelNames for $runtime has no route label: $names" >&2
        exit 1
    fi
done

for runtime in fpm roadrunner; do
    echo "--> The $type flame graph of reference_external on $runtime shows stacks and the controller"
    selector="{service_name=\\\"symfony-demo\\\",runtime=\\\"$runtime\\\",route=\\\"reference_external\\\"}"
    flamegraph=$(query SelectMergeStacktraces "{\"profileTypeID\":\"$type\",\"labelSelector\":\"$selector\",\"start\":$start_ms,\"end\":$end_ms}")
    if ! echo "$flamegraph" | grep -q 'ReferenceController::external'; then
        echo "The flame graph has no frame of ReferenceController::external: $flamegraph" | cut -c 1-2000 >&2
        exit 1
    fi
    # Non-empty stack: at least two distinct function-like frames (caller + leaf).
    frame_count=$(echo "$flamegraph" | grep -oE '[A-Za-z0-9_\\]+::[A-Za-z0-9_]+' | sort -u | wc -l | tr -d ' ')
    if [ "$frame_count" -lt 2 ]; then
        echo "The flame graph has fewer than two unique Class::method frames ($frame_count): $flamegraph" | cut -c 1-2000 >&2
        exit 1
    fi
    # Query window must return data for the traffic we just generated (end within the last minutes).
    if ! echo "$flamegraph" | grep -Eq '"flamebearer"|"Flamebearer"|"names"'; then
        echo "SelectMergeStacktraces returned no flamebearer payload for $runtime: $flamegraph" | cut -c 1-2000 >&2
        exit 1
    fi
done

echo 'Reference app profiles reached Pyroscope (stacks, wall/ns units, route labels, no raw URLs)'
