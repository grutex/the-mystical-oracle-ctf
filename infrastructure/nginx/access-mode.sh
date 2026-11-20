#!/bin/sh
set -eu

MAP_HOST=${MAP_HOST:-mapa.$CHALLENGE_HOST}
case "${ACCESS_MODE:-0}" in
    0) PORTAL_HOST=localhost; CHALLENGE_HOST=localhost; MAP_HOST=mapa.localhost
       PORTAL_HOSTS=$PORTAL_HOST; CHALLENGE_HOSTS=$CHALLENGE_HOST; MAP_HOSTS=$MAP_HOST ;;
    1) PORTAL_HOSTS=$PORTAL_HOST; CHALLENGE_HOSTS=$CHALLENGE_HOST; MAP_HOSTS=$MAP_HOST ;;
    2) PORTAL_HOSTS="$PORTAL_HOST localhost"
       CHALLENGE_HOSTS="$CHALLENGE_HOST localhost"
       MAP_HOSTS="$MAP_HOST mapa.localhost" ;;
    *) echo 'ACCESS_MODE deve ser 0 (localhost), 1 (domínio) ou 2 (ambos).' >&2; exit 1 ;;
esac
export PORTAL_HOST CHALLENGE_HOST MAP_HOST PORTAL_HOSTS CHALLENGE_HOSTS MAP_HOSTS
for prefix in spirit air fire water earth depth-water light-dark; do
    hosts=""
    for host in $CHALLENGE_HOSTS; do hosts="$hosts $prefix.$host"; done
    variable=$(printf '%s_HOSTS' "$prefix" | tr 'a-z-' 'A-Z_')
    export "$variable=$hosts"
done
exec /docker-entrypoint.sh "$@"
