#!/bin/sh

if [ ! -d /app/workspace ]
then
    echo "Workspace directory does not exist. Creating..."
    mkdir -p /app/workspace
fi

chown -R www-data:www-data /app/workspace && \
    chmod 775 /app/workspace

php-fpm
