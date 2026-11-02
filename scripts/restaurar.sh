#!/bin/sh
# Restaura uma cópia feita pelo backup.sh. APAGA os dados atuais e põe os da cópia no lugar.
# Uso: ./scripts/restaurar.sh ./backups/nexplangov_2026-10-22_020000
set -eu

PASTA="${1:?Informe a pasta da cópia, ex.: ./backups/nexplangov_2026-10-22_020000}"

[ -f "$PASTA/banco.dump" ] || { echo "Não achei $PASTA/banco.dump" >&2; exit 1; }
( cd "$PASTA" && sha256sum -c SHA256SUMS ) || { echo "A cópia está corrompida (soma de verificação não confere)." >&2; exit 1; }

printf "Isso vai SUBSTITUIR todos os dados atuais pelos da cópia %s. Digite RESTAURAR para continuar: " "$PASTA"
read -r resposta
[ "$resposta" = "RESTAURAR" ] || { echo "Cancelado."; exit 1; }

echo "Parando o sistema..."
docker compose stop app fila

echo "Restaurando o banco de dados..."
docker compose exec -T db sh -c 'dropdb -U "$POSTGRES_USER" --if-exists --force "$POSTGRES_DB" && createdb -U "$POSTGRES_USER" "$POSTGRES_DB"'
docker compose exec -T db sh -c 'pg_restore -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner' < "$PASTA/banco.dump"

echo "Restaurando os arquivos..."
docker compose run --rm -T --no-deps --entrypoint sh app -c 'rm -rf /var/www/html/storage/app/* && tar -xzf - -C /var/www/html/storage' < "$PASTA/arquivos.tar.gz"

echo "Subindo o sistema..."
docker compose start app fila

echo "Restauração concluída."
