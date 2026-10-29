{{-- La riga #!/usr/bin/env bash la aggiunge ScriptRenderer::render(): PHP scarta un #! iniziale. --}}
set -euo pipefail
cd "$(dirname "$0")"

usage() {
  printf 'Uso: %s start|stop|restart|status\n' "$0" >&2
  exit 2
}

ACTION="${1:-}"

case "$ACTION" in
  start|status)
@foreach ($scripts as $script)
    {!! $script !!} "$ACTION" all
@endforeach
    ;;
  stop)
@foreach ($reversed as $script)
    {!! $script !!} stop all
@endforeach
    ;;
  restart)
@foreach ($reversed as $script)
    {!! $script !!} stop all
@endforeach
@foreach ($scripts as $script)
    {!! $script !!} start all
@endforeach
    ;;
  *) usage ;;
esac
