#!/bin/bash
# ABOUTME: Sets up local development environment for Docker Compose

set -e

echo "🔧 Setting up Fossibot development environment..."

# Create mounts structure
echo "📁 Creating mounts/ directory structure..."
mkdir -p mounts/mosquitto/logs
mkdir -p mounts/fossibot/logs

# Copy Mosquitto config
echo "📋 Copying Mosquitto config..."
cp docker/mosquitto/config/mosquitto.conf mounts/mosquitto/

# Copy example config
echo "📋 Copying example config..."
if [ ! -f "mounts/fossibot/config.json" ]; then
    cp config/config.docker.json mounts/fossibot/config.json
    echo "⚠️  Please edit mounts/fossibot/config.json with your credentials"
else
    echo "✓ mounts/fossibot/config.json already exists"
fi

echo ""
echo "✅ Development environment ready!"
echo ""
echo "Next steps:"
echo "  1. Edit mounts/fossibot/config.json with your Fossibot credentials"
echo "  2. Run: docker compose up -d"
echo "  3. Check logs: docker compose logs -f fossibot-bridge"
echo ""
