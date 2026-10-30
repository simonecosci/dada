{{-- The #!/usr/bin/env bash line is added by ScriptRenderer::render(): PHP discards a leading #!. --}}
set -euo pipefail
cd "$(dirname "$0")"

usage() {
  printf 'Usage: %s start|stop|restart|status\n' "$0" >&2
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
@foreach ($scripts as $script)
    {!! $script !!} restart all
@endforeach
    ;;
  *) usage ;;
esac
