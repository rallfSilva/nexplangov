#!/bin/sh
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "ERRO: defina APP_KEY no arquivo .env (gere com: docker compose run --rm app php artisan key:generate --show)" >&2
    exit 1
fi

# Espera o PostgreSQL ficar pronto
tentativas=0
until pg_isready -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USERNAME" >/dev/null 2>&1; do
    tentativas=$((tentativas + 1))
    if [ "$tentativas" -ge 30 ]; then
        echo "ERRO: o banco de dados não respondeu em 60 segundos." >&2
        exit 1
    fi
    sleep 2
done

# Só o contêiner principal migra o banco (a fila apenas espera)
if [ "${NEXPLANGOV_MIGRAR:-true}" = "true" ]; then
    php artisan migrate --force
    php artisan db:seed --class='Database\Seeders\PerfisSeeder' --force

    if [ "${NEXPLANGOV_DEMO:-false}" = "true" ]; then
        php artisan db:seed --class='Database\Seeders\DemoSeeder' --force
    fi
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
