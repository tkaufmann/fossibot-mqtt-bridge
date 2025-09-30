#!/bin/bash
# ABOUTME: Installs Fossibot Bridge as systemd service

set -e

echo "Fossibot MQTT Bridge - systemd Installation"
echo "==========================================="
echo

# Check if running as root
if [ "$EUID" -ne 0 ]; then
    echo "Error: This script must be run as root (use sudo)"
    exit 1
fi

# Create fossibot user if not exists
if ! id -u fossibot >/dev/null 2>&1; then
    echo "Creating fossibot user..."
    useradd --system --shell /usr/sbin/nologin --home-dir /opt/fossibot-bridge fossibot
else
    echo "✅ User fossibot already exists"
fi

# Create directories
echo "Creating directories..."
mkdir -p /opt/fossibot-bridge
mkdir -p /etc/fossibot
mkdir -p /var/log/fossibot

# Set ownership
chown -R fossibot:fossibot /opt/fossibot-bridge
chown -R fossibot:fossibot /var/log/fossibot
chmod 700 /etc/fossibot

echo "✅ Directories created"

# Copy files
echo "Copying bridge files..."
cp -r ../src /opt/fossibot-bridge/
cp -r ../daemon /opt/fossibot-bridge/
cp -r ../vendor /opt/fossibot-bridge/
cp ../composer.json /opt/fossibot-bridge/
cp ../composer.lock /opt/fossibot-bridge/

chown -R fossibot:fossibot /opt/fossibot-bridge

echo "✅ Files copied"

# Copy config example
if [ ! -f /etc/fossibot/config.json ]; then
    echo "Copying example config..."
    cp ../config/example.json /etc/fossibot/config.json
    chown fossibot:fossibot /etc/fossibot/config.json
    chmod 600 /etc/fossibot/config.json
    echo "⚠️  Please edit /etc/fossibot/config.json with your credentials!"
else
    echo "✅ Config already exists at /etc/fossibot/config.json"
fi

# Install systemd unit
echo "Installing systemd service..."
cp fossibot-bridge.service /etc/systemd/system/
chmod 644 /etc/systemd/system/fossibot-bridge.service

# Reload systemd
systemctl daemon-reload

echo "✅ systemd service installed"
echo
echo "Next steps:"
echo "  1. Edit config: sudo nano /etc/fossibot/config.json"
echo "  2. Enable service: sudo systemctl enable fossibot-bridge"
echo "  3. Start service: sudo systemctl start fossibot-bridge"
echo "  4. Check status: sudo systemctl status fossibot-bridge"
echo "  5. View logs: sudo journalctl -u fossibot-bridge -f"
echo
