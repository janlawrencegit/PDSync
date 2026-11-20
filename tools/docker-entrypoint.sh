#!/bin/sh
# Container start: a host's mounted volume (Railway, Docker) is often owned by root, so data/ is handed to Apache's
# user before Apache starts. PORT is set by hosts such as Railway; Apache listens on it (default 80).
set -e
mkdir -p /var/www/app/data
chown -R www-data:www-data /var/www/app/data
exec apache2-foreground
