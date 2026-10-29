{{-- La riga #!/usr/bin/env bash la aggiunge ScriptRenderer::render(): PHP scarta un #! iniziale. --}}
set -euo pipefail
cd "$(dirname "$0")"

usage() {
  printf 'Uso: %s start|stop|restart|status [servizio|all]\n' "$0" >&2
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
    start) docker service scale {!! $service['full'] !!}={!! $service['replicas'] !!} ;;
    stop) docker service scale {!! $service['full'] !!}=0 ;;
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
    printf 'Servizio non valido per %s: %s\n' {!! $slug !!} "$TARGET" >&2
    exit 2
    ;;
esac

for target in ${TARGETS[@]+"${TARGETS[@]}"}; do
  "$target" "$ACTION"
done
