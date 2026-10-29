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

# Ruling P12: prima lo stack (crea le reti, avvia i servizi), poi gli init,
# poi il riavvio dei servizi delle installazioni che hanno init.
refresh_services() {
@foreach ($updates as $update)
  docker service update --force {!! $update !!}
@endforeach
  :
}

case "$ACTION" in
  start|restart)
    # --detach=false (Docker >= 26 sui manager swarm): attende la convergenza dei servizi prima degli init.
    docker stack deploy --detach=false -c docker-compose.yml --with-registry-auth --prune {!! $stack !!}
    run_inits
    refresh_services
    ;;
  stop) docker stack rm {!! $stack !!} ;;
  status) docker stack services {!! $stack !!} ;;
esac
