{{-- The #!/usr/bin/env bash line is added by ScriptRenderer::render(): PHP discards a leading #!. --}}
set -euo pipefail
cd "$(dirname "$0")"

usage() {
  printf 'Usage: %s start|stop|restart|status [service|all]\n' "$0" >&2
  exit 2
}

ACTION="${1:-}"
TARGET="${2:-all}"

case "$ACTION" in
  start|stop|restart|status) ;;
  *) usage ;;
esac

case "$TARGET" in
  all) SERVICES=({!! $allServices !!}) ;;
@foreach ($services as $service)
  {!! $service !!}) SERVICES=({!! $service !!}) ;;
@endforeach
  *)
    printf 'Invalid service for %s: %s\n' {!! $slug !!} "$TARGET" >&2
    exit 2
    ;;
esac

run_inits() {
@foreach ($inits as $init)
  docker compose --profile init run --rm {!! $init !!}
@endforeach
  :
}

start_services() {
  if [ "$TARGET" = all ]; then
    run_inits
  fi
  if [ "${#SERVICES[@]}" -gt 0 ]; then
    docker compose up -d "${SERVICES[@]}"
  fi
}

stop_services() {
  if [ "${#SERVICES[@]}" -gt 0 ]; then
    docker compose stop -t 10 "${SERVICES[@]}"
    docker compose rm -f "${SERVICES[@]}"
  fi
}

# Ruling F1: no rm, containers are recreated in place (anonymous volumes stay).
restart_services() {
  if [ "$TARGET" = all ]; then
    run_inits
  fi
  if [ "${#SERVICES[@]}" -gt 0 ]; then
    docker compose up -d --force-recreate "${SERVICES[@]}"
  fi
}

status_services() {
  if [ "${#SERVICES[@]}" -gt 0 ]; then
    docker compose ps "${SERVICES[@]}"
  fi
}

case "$ACTION" in
  start) start_services ;;
  stop) stop_services ;;
  restart) restart_services ;;
  status) status_services ;;
esac
