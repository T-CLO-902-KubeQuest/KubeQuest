#!/usr/bin/env bash
# Entrypoint for the deliberately BROKEN sample-app image (tag: broken).
#
# It is the SAME application as the healthy image; it only adds a fault-injection
# layer on top. A single fault is selected at runtime by the BREAK_MODE env var,
# so the image stays inert (behaves exactly like the normal one) unless armed.
#
#   BREAK_MODE=crash  -> the container's main process exits non-zero after a short
#                        delay, like a process that dies on a fatal error. The
#                        kubelet restarts it again and again -> CrashLoopBackOff.
#   BREAK_MODE=oom    -> a process leaks memory until the container passes its
#                        memory limit. The kernel OOM-kills it -> OOMKilled +
#                        restart, and the climb is visible on the memory metric.
#   BREAK_MODE=cpu    -> busy loops saturate the container's CPU limit; the kernel
#                        throttles the cgroup, Apache is starved and /readyz times
#                        out. Visible on the CPU metric.
#   (unset / anything) -> no fault armed: run the app normally (safe default).
#
# Only one mode is meant to be active at a time: a crash would pre-empt a leak,
# and mixing signals makes the demo unreadable. Pick one per run.
set -euo pipefail

MODE="${BREAK_MODE:-none}"
echo "[break-entrypoint] BREAK_MODE=${MODE}"

# Start the real app (php:8.3-apache's own entrypoint + Apache) in the background
# so probes have something to hit until the injected fault bites.
run_app_background() {
  docker-php-entrypoint apache2-foreground &
}

case "${MODE}" in
  crash)
    run_app_background
    DELAY="${BREAK_CRASH_DELAY:-15}"
    echo "[break-entrypoint] crash: serving, then exiting non-zero in ${DELAY}s"
    sleep "${DELAY}"
    echo "[break-entrypoint] simulating a fatal crash (exit 1)"
    exit 1
    ;;

  oom)
    run_app_background
    echo "[break-entrypoint] oom: leaking ~50 MiB/s until the memory limit -> OOMKilled"
    # `exec` makes this leaker the container's main process (PID 1). Its resident
    # memory is by far the largest in the cgroup, so when the limit is reached the
    # kernel OOM-kills it; because it is PID 1, the whole container is reported
    # OOMKilled and restarted. memory_limit=-1 lifts PHP's own guardrail so the
    # cgroup limit is the only ceiling.
    exec php -d memory_limit=-1 -r '$b=[]; while (true) { $b[] = str_repeat("x", 10 * 1024 * 1024); usleep(200000); }'
    ;;

  cpu)
    run_app_background
    echo "[break-entrypoint] cpu: saturating the CPU limit -> throttling -> readiness timeouts"
    # Two spinners guarantee the cgroup wants more than its CPU limit, so it is
    # throttled and Apache is starved. One runs in the background, the other as
    # PID 1 to keep the container alive.
    php -r 'while (true) {}' &
    exec php -r 'while (true) {}'
    ;;

  *)
    echo "[break-entrypoint] no fault armed; running the app normally"
    exec docker-php-entrypoint apache2-foreground
    ;;
esac
