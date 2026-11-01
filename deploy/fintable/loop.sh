#!/bin/sh
set -u
stopping=0
phase=collect
child=''
stop() {
    stopping=1
    if [ "$phase" = sleep ] && [ -n "$child" ]; then
        kill "$child" 2>/dev/null || :
    fi
}
trap stop TERM INT
while [ "$stopping" -eq 0 ]; do
    phase=collect
    php /opt/fintable/worker.php &
    child=$!
    wait "$child"
    result=$?
    if [ "$stopping" -eq 1 ]; then
        # Let an active native job finish before shutting down.
        wait "$child" 2>/dev/null || :
        break
    fi
    if [ "$result" -eq 0 ]; then
        touch /tmp/fintable-worker-ok
    fi
    phase=sleep
    sleep 60 &
    child=$!
    wait "$child" || :
done
