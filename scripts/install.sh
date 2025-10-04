#!/bin/bash
# ABOUTME: Production installation script for Fossibot MQTT Bridge
# Installs bridge as systemd service with dedicated user and FHS-compliant paths

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# =============================================================================
# CONFIGURATION
# =============================================================================

INSTALL_DIR="/opt/fossibot-bridge"
CONFIG_DIR="/etc/fossibot"
LOG_DIR="/var/log/fossibot"
CACHE_DIR="/var/lib/fossibot"
SYSTEMD_DIR="/etc/systemd/system"
BIN_DIR="/usr/local/bin"

SERVICE_NAME="fossibot-bridge"
SERVICE_USER="fossibot"
SERVICE_GROUP="fossibot"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# =============================================================================
# HELPER FUNCTIONS
# =============================================================================

print_header() {
    echo ""
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""
}

print_step() {
    echo -e "${GREEN}▶${NC} $1"
}

print_warn() {
    echo -e "${YELLOW}⚠️  $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

check_root() {
    if [ "$EUID" -ne 0 ]; then
        print_error "This script must be run as root"
        echo "Please run: sudo $0"
        exit 1
    fi
}

check_dependencies() {
    local missing=()

    for cmd in php composer jq mosquitto; do
        if ! command -v "$cmd" &> /dev/null; then
            missing+=("$cmd")
        fi
    done

    if [ ${#missing[@]} -gt 0 ]; then
        print_error "Missing dependencies: ${missing[*]}"
        echo ""
        echo "Install with:"
        echo "  sudo apt-get update"
        echo "  sudo apt-get install php8.2-cli php8.2-mbstring php8.2-xml composer jq mosquitto"
        exit 1
    fi

    # Check PHP version
    local php_version=$(php -r "echo PHP_VERSION;")
    local php_major=$(echo "$php_version" | cut -d. -f1)
    local php_minor=$(echo "$php_version" | cut -d. -f2)

    if [ "$php_major" -lt 8 ] || ([ "$php_major" -eq 8 ] && [ "$php_minor" -lt 2 ]); then
        print_error "PHP 8.2+ required, found: $php_version"
        exit 1
    fi

    print_step "All dependencies satisfied (PHP $php_version)"
}

# =============================================================================
# INSTALLATION STEPS
# =============================================================================

step_create_user() {
    print_step "Creating system user: $SERVICE_USER"

    if id "$SERVICE_USER" &>/dev/null; then
        print_warn "User $SERVICE_USER already exists, skipping"
    else
        useradd --system --no-create-home --shell /bin/false "$SERVICE_USER"
        echo "   ✅ User created"
    fi
}

step_create_directories() {
    print_step "Creating FHS-compliant directory structure"

    # Install directory
    if [ -d "$INSTALL_DIR" ]; then
        print_warn "$INSTALL_DIR already exists"
        read -p "   Overwrite? (y/N): " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Yy]$ ]]; then
            print_error "Installation aborted"
            exit 1
        fi
        rm -rf "$INSTALL_DIR"
    fi

    mkdir -p "$INSTALL_DIR"
    echo "   ✅ $INSTALL_DIR"

    # Config directory
    mkdir -p "$CONFIG_DIR"
    echo "   ✅ $CONFIG_DIR"

    # Log directory
    mkdir -p "$LOG_DIR"
    echo "   ✅ $LOG_DIR"

    # Cache directory
    mkdir -p "$CACHE_DIR"
    echo "   ✅ $CACHE_DIR"

    # Runtime directory (for PID file) - systemd creates this via RuntimeDirectory
    mkdir -p "/var/run/fossibot"
    echo "   ✅ /var/run/fossibot"
}

step_copy_files() {
    print_step "Copying application files to $INSTALL_DIR"

    # Copy application code
    cp -r "$PROJECT_ROOT/src" "$INSTALL_DIR/"
    cp -r "$PROJECT_ROOT/daemon" "$INSTALL_DIR/"
    cp -r "$PROJECT_ROOT/vendor" "$INSTALL_DIR/"
    cp "$PROJECT_ROOT/composer.json" "$INSTALL_DIR/"
    cp "$PROJECT_ROOT/composer.lock" "$INSTALL_DIR/"

    echo "   ✅ Application files copied"
}

step_install_config() {
    print_step "Installing configuration"

    local config_file="$CONFIG_DIR/config.json"

    if [ -f "$config_file" ]; then
        print_warn "Config already exists: $config_file"
        echo "   Keeping existing config (use upgrade.sh to merge changes)"
    else
        # Create config from example
        cat > "$config_file" << 'EOF'
{
  "accounts": [
    {
      "email": "user@example.com",
      "password": "CHANGE_ME",
      "enabled": false
    }
  ],
  "mosquitto": {
    "host": "localhost",
    "port": 1883,
    "username": null,
    "password": null,
    "client_id": "fossibot_bridge"
  },
  "daemon": {
    "log_file": "/var/log/fossibot/bridge.log",
    "log_level": "info",
    "pid_file": "/var/run/fossibot/bridge.pid"
  },
  "cache": {
    "directory": "/var/lib/fossibot",
    "token_ttl_safety_margin": 300,
    "device_list_ttl": 86400,
    "device_refresh_interval": 86400
  },
  "bridge": {
    "status_publish_interval": 60,
    "device_poll_interval": 30,
    "reconnect_delay_min": 5,
    "reconnect_delay_max": 60
  },
  "debug": {
    "log_raw_registers": false,
    "log_update_source": false
  }
}
EOF
        chmod 600 "$config_file"
        echo "   ✅ Config created: $config_file"
        echo ""
        print_warn "IMPORTANT: Edit $config_file and add your Fossibot credentials!"
    fi
}

step_install_systemd_service() {
    print_step "Installing systemd service"

    local service_file="$SYSTEMD_DIR/$SERVICE_NAME.service"

    cat > "$service_file" << EOF
[Unit]
Description=Fossibot MQTT Bridge Daemon
Documentation=https://github.com/youruser/fossibot-php2
After=network.target mosquitto.service
Wants=mosquitto.service

[Service]
Type=simple
User=$SERVICE_USER
Group=$SERVICE_GROUP
WorkingDirectory=$INSTALL_DIR
ExecStart=/usr/bin/php $INSTALL_DIR/daemon/fossibot-bridge.php --config $CONFIG_DIR/config.json
Restart=always
RestartSec=10
StandardOutput=journal
StandardError=journal

# Security hardening
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=$LOG_DIR $CACHE_DIR
RuntimeDirectory=fossibot

# Resource limits
LimitNOFILE=65536
MemoryMax=512M

# Environment
Environment="PHP_MEMORY_LIMIT=256M"

[Install]
WantedBy=multi-user.target
EOF

    chmod 644 "$service_file"
    echo "   ✅ Service file created: $service_file"

    systemctl daemon-reload
    echo "   ✅ systemd reloaded"
}

step_install_control_script() {
    print_step "Installing control script"

    local ctl_script="$BIN_DIR/fossibot-bridge-ctl"

    cp "$PROJECT_ROOT/bin/fossibot-bridge-ctl" "$ctl_script"
    chmod +x "$ctl_script"

    echo "   ✅ Control script installed: $ctl_script"
}

step_set_permissions() {
    print_step "Setting file permissions"

    # Application directory (read-only)
    chown -R root:root "$INSTALL_DIR"
    chmod -R 755 "$INSTALL_DIR"
    echo "   ✅ $INSTALL_DIR → root:root"

    # Config directory (read-only for service user)
    chown -R root:"$SERVICE_GROUP" "$CONFIG_DIR"
    chmod 750 "$CONFIG_DIR"
    chmod 640 "$CONFIG_DIR"/*.json
    echo "   ✅ $CONFIG_DIR → root:$SERVICE_GROUP"

    # Log directory (writable)
    chown -R "$SERVICE_USER":"$SERVICE_GROUP" "$LOG_DIR"
    chmod 755 "$LOG_DIR"
    echo "   ✅ $LOG_DIR → $SERVICE_USER:$SERVICE_GROUP"

    # Cache directory (writable)
    chown -R "$SERVICE_USER":"$SERVICE_GROUP" "$CACHE_DIR"
    chmod 755 "$CACHE_DIR"
    echo "   ✅ $CACHE_DIR → $SERVICE_USER:$SERVICE_GROUP"

    # Runtime directory (writable)
    chown -R "$SERVICE_USER":"$SERVICE_GROUP" "/var/run/fossibot"
    chmod 755 "/var/run/fossibot"
    echo "   ✅ /var/run/fossibot → $SERVICE_USER:$SERVICE_GROUP"
}

step_enable_service() {
    print_step "Enabling service (auto-start on boot)"

    systemctl enable "$SERVICE_NAME"
    echo "   ✅ Service enabled"
}

step_validate_config() {
    print_step "Validating configuration"

    if fossibot-bridge-ctl validate; then
        echo "   ✅ Configuration valid"
    else
        print_warn "Configuration validation failed (expected if credentials not set yet)"
    fi
}

# =============================================================================
# MAIN
# =============================================================================

main() {
    print_header "Fossibot MQTT Bridge - Installation"

    echo "This will install Fossibot Bridge to:"
    echo "  • Application: $INSTALL_DIR"
    echo "  • Config:      $CONFIG_DIR"
    echo "  • Logs:        $LOG_DIR"
    echo "  • Cache:       $CACHE_DIR"
    echo "  • Service:     systemd"
    echo ""
    read -p "Continue? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Installation aborted"
        exit 0
    fi

    check_root
    check_dependencies

    step_create_user
    step_create_directories
    step_copy_files
    step_install_config
    step_install_systemd_service
    step_install_control_script
    step_set_permissions
    step_enable_service
    step_validate_config

    print_header "Installation Complete! 🎉"

    echo "Next steps:"
    echo ""
    echo "1. Edit configuration:"
    echo "   sudo nano $CONFIG_DIR/config.json"
    echo ""
    echo "2. Validate configuration:"
    echo "   fossibot-bridge-ctl validate"
    echo ""
    echo "3. Start service:"
    echo "   fossibot-bridge-ctl start"
    echo ""
    echo "4. Check status:"
    echo "   fossibot-bridge-ctl status"
    echo ""
    echo "5. View logs:"
    echo "   fossibot-bridge-ctl logs"
    echo ""
}

main "$@"
