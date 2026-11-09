#!/bin/sh
# Arranque en Render. El disco gratuito es efímero: si no hay base de datos, se crea con los datos de ejemplo.
set -e
cd /app
if [ ! -s database/smartcell.sqlite ]; then
  echo "Creando base de datos de ejemplo..."
  php database/seeder.php
fi
exec php -d upload_max_filesize=3M -d post_max_size=4M -S 0.0.0.0:${PORT:-10000} dev-router.php
