{{-- La riga #!/usr/bin/env bash la aggiunge ScriptRenderer::render(): PHP scarta un #! iniziale. --}}
set -euo pipefail
cd "$(dirname "$0")"

usage() {
  printf 'Uso: %s start|stop|restart|status\n' "$0" >&2
  exit 2
}

ACTION="${1:-}"

case "$ACTION" in
  start|stop|restart|status) ;;
  *) usage ;;
esac

# .env contiene righe KEY=value: si esportano senza mai eseguirle come codice.
if [ -f .env ]; then
  while IFS= read -r line || [ -n "$line" ]; do [ -z "$line" ] && continue; export "$line"; done < .env
fi

run_inits() {
@foreach ($runs as $run)
  {!! $run !!}
@endforeach
  :
}

case "$ACTION" in
  start|restart)
    run_inits
    docker stack deploy -c docker-compose.yml --with-registry-auth --prune {!! $stack !!}
    ;;
  stop) docker stack rm {!! $stack !!} ;;
  status) docker stack services {!! $stack !!} ;;
esac
