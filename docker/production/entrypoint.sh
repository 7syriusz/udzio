#!/bin/sh
# Pamięć podręczna konfiguracji, tras, widoków i zdarzeń przy starcie kontenera PHP-FPM.
# Kontenery kolejki i harmonogramu (inne polecenie) startują bez tego kroku.
set -e

if [ "$1" = "php-fpm" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
fi

exec "$@"
