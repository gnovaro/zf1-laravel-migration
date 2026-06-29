#!/bin/bash
set -e

if [ ! -d /app/vendor ]; then
    echo "→ Installing project dependencies..."
    composer install --no-interaction --quiet
    echo "→ Done."
fi

exec "$@"
