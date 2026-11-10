#!/bin/sh
# Respaldo automático diario de la BD -- corre como servicio aparte en
# docker-compose (ver servicio "backup"). Guarda en /backups (montado a
# backups/ en el host, ya gitignored) y borra respaldos de más de
# RETENTION_DIAS para no llenar el disco indefinidamente.
set -eu

RETENTION_DIAS="${BACKUP_RETENTION_DIAS:-14}"
DEST="/backups"

mkdir -p "$DEST"

respaldar() {
  local stamp="$(date +%Y-%m-%d_%H%M%S)"
  local archivo="$DEST/auto_${stamp}.sql.gz"
  local tmp="$DEST/.tmp_${stamp}.sql"
  echo "[$(date -Iseconds)] Iniciando respaldo -> $archivo"

  # mysqldump se escribe primero a un archivo plano y se revisa SU propio
  # exit code directamente -- "mysqldump | gzip > archivo" parecía correcto
  # pero en sh puro (sin pipefail) el exit code de un pipe es el del ÚLTIMO
  # comando (gzip), así que un mysqldump fallido con gzip "exitoso" sobre una
  # entrada vacía se reportaba como respaldo OK, y la rotación de abajo podía
  # terminar borrando el último respaldo bueno mientras solo quedaban rotos.
  if mysqldump -h "${DB_HOST:-db}" -u"${DB_USER:-root}" -p"${DB_PASS:-root}" "${DB_NAME:-sistema_requisiciones}" > "$tmp" && [ -s "$tmp" ]; then
    gzip -c "$tmp" > "$archivo"
    rm -f "$tmp"
    echo "[$(date -Iseconds)] Respaldo OK ($(du -h "$archivo" | cut -f1))"
    # Rotación solo corre si ESTE respaldo salió bien -- nunca podar lo viejo
    # a partir de un intento fallido.
    find "$DEST" -name 'auto_*.sql.gz' -mtime "+${RETENTION_DIAS}" -delete
  else
    echo "[$(date -Iseconds)] ERROR al respaldar (mysqldump falló o el dump quedó vacío)" >&2
    rm -f "$tmp" "$archivo"
  fi
}

# Espera a que la BD esté lista antes del primer respaldo
until mysqladmin ping -h "${DB_HOST:-db}" -u"${DB_USER:-root}" -p"${DB_PASS:-root}" --silent 2>/dev/null; do
  echo "Esperando a la base de datos..."
  sleep 5
done

while true; do
  respaldar
  sleep 86400
done
