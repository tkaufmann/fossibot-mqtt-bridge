#!/bin/sh
# ABOUTME: Docker entrypoint script with flexible UID/GID handling
set -e

# Default UID/GID to 1000 if not set
PUID="${PUID:-1000}"
PGID="${PGID:-1000}"

# Get current fossibot user UID/GID
CURRENT_UID=$(id -u fossibot)
CURRENT_GID=$(id -g fossibot)

# Only modify user if PUID/PGID are different
if [ "$PUID" != "$CURRENT_UID" ] || [ "$PGID" != "$CURRENT_GID" ]; then
    echo "Updating fossibot user to UID=$PUID, GID=$PGID"

    # Modify group
    if [ "$PGID" != "$CURRENT_GID" ]; then
        delgroup fossibot 2>/dev/null || true
        addgroup -g "$PGID" fossibot 2>/dev/null || true
    fi

    # Modify user
    if [ "$PUID" != "$CURRENT_UID" ]; then
        deluser fossibot 2>/dev/null || true
        adduser -D -u "$PUID" -G fossibot fossibot 2>/dev/null || true
    fi
fi

# Fix permissions on mounted directories
echo "Fixing permissions on /var/lib/fossibot and /var/log/fossibot"
chown -R fossibot:fossibot /var/lib/fossibot /var/log/fossibot 2>/dev/null || true

# Drop to fossibot user and execute command
exec su-exec fossibot "$@"
