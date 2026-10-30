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

@foreach ($services as $service)
{!! $service['fn'] !!}() {
  case "$1" in
@if ($service['mode'] === null)
    start) docker service scale {!! $service['full'] !!}={!! $service['replicas'] !!} ;;
    stop) docker service scale {!! $service['full'] !!}=0 ;;
@else
    start|stop)
      printf 'service %s in mode %s: start/stop not supported, use restart\n' {!! $service['name'] !!} {!! $service['mode'] !!} >&2
      if [ "$TARGET" != all ]; then
        exit 2
      fi
      ;;
@endif
    restart) docker service update --force {!! $service['full'] !!} ;;
    status) docker service ps {!! $service['full'] !!} ;;
  esac
}

@endforeach
case "$TARGET" in
  all) TARGETS=({!! $allFns !!}) ;;
@foreach ($services as $service)
  {!! $service['name'] !!}) TARGETS=({!! $service['fn'] !!}) ;;
@endforeach
  *)
    printf 'Invalid service for %s: %s\n' {!! $slug !!} "$TARGET" >&2
    exit 2
    ;;
esac

for target in ${TARGETS[@]+"${TARGETS[@]}"}; do
  "$target" "$ACTION"
done
