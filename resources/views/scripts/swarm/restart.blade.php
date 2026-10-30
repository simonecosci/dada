{{-- The #!/usr/bin/env bash line is added by ScriptRenderer::render(): PHP discards a leading #!. --}}
set -euo pipefail
cd "$(dirname "$0")"

usage() {
  printf 'Usage: %s start|stop|restart|status\n' "$0" >&2
  exit 2
}

ACTION="${1:-}"

case "$ACTION" in
  start|stop|restart|status) ;;
  *) usage ;;
esac

# .env contains KEY=value lines: they are exported without ever running them as code.
if [ -f .env ]; then
  while IFS= read -r line || [ -n "$line" ]; do [ -z "$line" ] && continue; export "$line"; done < .env
fi

run_inits() {
@foreach ($runs as $run)
  {!! $run !!}
@endforeach
  :
}

# Ruling P12: first the stack (creates the networks, starts the services), then the inits,
# then the restart of the services of the installations that have inits.
refresh_services() {
@foreach ($updates as $update)
  docker service update --force {!! $update !!}
@endforeach
  :
}

case "$ACTION" in
  start|restart)
    # --detach=false (Docker >= 26 on swarm managers): waits for the services to converge before the inits.
    docker stack deploy --detach=false -c docker-compose.yml --with-registry-auth --prune {!! $stack !!}
    run_inits
    refresh_services
    ;;
  stop) docker stack rm {!! $stack !!} ;;
  status) docker stack services {!! $stack !!} ;;
esac
