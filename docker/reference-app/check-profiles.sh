#!/bin/sh
# Checks that the profiles of the reference app reach Pyroscope with their labels: sends requests
# to both variants, then asks Pyroscope for the routes it has seen for each runtime and for the
# frames of one route. Run it after `make app-up`.
#
# PHP-FPM profiles go through the spool and app-drain, RoadRunner profiles straight to Pyroscope.
set -eu

pyroscope=${PYROSCOPE_URL:-http://127.0.0.1:4040}
requests=${REQUESTS:-100}
timeout=${TIMEOUT:-90}

query() {
    curl -fsS -H 'Content-Type: application/json' -d "$2" "$pyroscope/querier.v1.QuerierService/$1"
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
type=$(echo "$types" | grep -o '"ID":"[^"]*:wall:nanoseconds:[^"]*"' | head -n 1 | cut -d '"' -f 4)
if [ -z "$type" ]; then
    echo "Pyroscope lists no wall-time profile type: $types" >&2
    exit 1
fi

for runtime in fpm roadrunner; do
    echo "--> The $type flame graph of reference_external on $runtime shows the controller"
    selector="{service_name=\\\"symfony-demo\\\",runtime=\\\"$runtime\\\",route=\\\"reference_external\\\"}"
    flamegraph=$(query SelectMergeStacktraces "{\"profileTypeID\":\"$type\",\"labelSelector\":\"$selector\",\"start\":$start_ms,\"end\":$end_ms}")
    if ! echo "$flamegraph" | grep -q 'ReferenceController::external'; then
        echo "The flame graph has no frame of ReferenceController::external: $flamegraph" | cut -c 1-2000 >&2
        exit 1
    fi
done

echo 'Reference app profiles reached Pyroscope'
