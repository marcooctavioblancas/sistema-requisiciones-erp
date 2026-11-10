#!/bin/sh
set -e

# Fix permissions on bind-mounted directories (chmod only — chown done at build time)
if [ -d /var/www/html/uploads ]; then
    chmod -R 775 /var/www/html/uploads 2>/dev/null || true
fi

if [ -d /var/www/html/logs ]; then
    chmod -R 775 /var/www/html/logs 2>/dev/null || true
fi

# Dependencias de Composer (PHPUnit) -- el bind mount de docker-compose reemplaza
# TODO /var/www/html con el contenido del host al arrancar, así que cualquier
# "composer install" corrido en tiempo de build queda tapado; hay que correrlo
# aquí, después de que el mount ya está activo. Solo instala si falta o si
# composer.json cambió (composer install ya es idempotente por su cuenta).
if [ -f /var/www/html/composer.json ]; then
    composer install --no-interaction --no-progress --quiet -d /var/www/html 2>&1 || true
fi

exec "$@"
