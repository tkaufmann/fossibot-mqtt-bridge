#!/bin/bash
# ABOUTME: Upgrade script for Fossibot MQTT Bridge with config preservation
# Performs in-place update, shows config diff, preserves user changes

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

# Configuration
INSTALL_DIR="/opt/fossibot-bridge"
CONFIG_DIR="/etc/fossibot"
SERVICE_NAME="fossibot-bridge"
CONTROL_SCRIPT="/usr/local/bin/fossibot-bridge-ctl"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

BACKUP_DIR="/tmp/fossibot-backup-$(date +%Y%m%d-%H%M%S)"

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

check_installation() {
    if [ ! -d "$INSTALL_DIR" ]; then
        print_error "Fossibot Bridge not installed in $INSTALL_DIR"
        echo "Run install.sh first"
        exit 1
    fi
}

check_jq() {
    if ! command -v jq &> /dev/null; then
        print_error "jq is required for config diff"
        echo "Install with: sudo apt-get install jq"
        exit 1
    fi
}

# =============================================================================
# UPGRADE STEPS
# =============================================================================

step_stop_service() {
    print_step "Stopping service"

    if systemctl is-active --quiet "$SERVICE_NAME"; then
        systemctl stop "$SERVICE_NAME"
        echo "   ✅ Service stopped"
    else
        echo "   ℹ️  Service not running"
    fi
}

step_backup() {
    print_step "Creating backup"

    mkdir -p "$BACKUP_DIR"

    # Backup application
    cp -r "$INSTALL_DIR" "$BACKUP_DIR/install"
    echo "   ✅ Application backed up"

    # Backup config
    cp -r "$CONFIG_DIR" "$BACKUP_DIR/config"
    echo "   ✅ Config backed up"

    echo "   📁 Backup location: $BACKUP_DIR"
}

step_show_config_diff() {
    print_step "Checking for config changes"

    local old_config="$CONFIG_DIR/config.json"
    local new_config="$PROJECT_ROOT/config/example.json"

    if [ ! -f "$old_config" ]; then
        echo "   ℹ️  No existing config found"
        return
    fi

    # Extract config structure (keys only, no values)
    local old_keys=$(jq -r 'paths | join(".")' "$old_config" 2>/dev/null | sort)
    local new_keys=$(jq -r 'paths | join(".")' "$new_config" 2>/dev/null | sort)

    # Find added keys (in new, not in old)
    local added=$(comm -13 <(echo "$old_keys") <(echo "$new_keys"))

    # Find removed keys (in old, not in new)
    local removed=$(comm -23 <(echo "$old_keys") <(echo "$new_keys"))

    if [ -n "$added" ]; then
        echo ""
        echo -e "${YELLOW}New config options available:${NC}"
        echo "$added" | while read -r key; do
            local value=$(jq -r ".${key}" "$new_config" 2>/dev/null || echo "null")
            echo "  + $key = $value"
        done
        echo ""
        print_warn "You may want to add these to your config manually"
    fi

    if [ -n "$removed" ]; then
        echo ""
        echo -e "${YELLOW}Obsolete config options:${NC}"
        echo "$removed" | while read -r key; do
            echo "  - $key"
        done
        echo ""
        print_warn "These options are no longer used"
    fi

    if [ -z "$added" ] && [ -z "$removed" ]; then
        echo "   ✅ No config changes detected"
    fi
}

step_update_application() {
    print_step "Updating application files"

    # Remove old code
    rm -rf "$INSTALL_DIR/src"
    rm -rf "$INSTALL_DIR/daemon"
    rm -rf "$INSTALL_DIR/vendor"

    # Copy new code
    cp -r "$PROJECT_ROOT/src" "$INSTALL_DIR/"
    cp -r "$PROJECT_ROOT/daemon" "$INSTALL_DIR/"
    cp -r "$PROJECT_ROOT/vendor" "$INSTALL_DIR/"
    cp "$PROJECT_ROOT/composer.json" "$INSTALL_DIR/"
    cp "$PROJECT_ROOT/composer.lock" "$INSTALL_DIR/"

    echo "   ✅ Application updated"
}

step_update_control_script() {
    print_step "Updating control script"

    cp "$PROJECT_ROOT/bin/fossibot-bridge-ctl" "$CONTROL_SCRIPT"
    chmod +x "$CONTROL_SCRIPT"

    echo "   ✅ Control script updated"
}

step_update_service_file() {
    print_step "Updating systemd service file"

    local service_file="/etc/systemd/system/$SERVICE_NAME.service"

    # Check if service file changed
    if ! diff -q "$PROJECT_ROOT/daemon/fossibot-bridge.service" "$service_file" &>/dev/null; then
        cp "$PROJECT_ROOT/daemon/fossibot-bridge.service" "$service_file"
        systemctl daemon-reload
        echo "   ✅ Service file updated"
    else
        echo "   ℹ️  Service file unchanged"
    fi
}

step_validate_config() {
    print_step "Validating configuration"

    if fossibot-bridge-ctl validate; then
        echo "   ✅ Configuration valid"
    else
        print_error "Configuration validation failed!"
        echo ""
        echo "To rollback:"
        echo "  sudo cp -r $BACKUP_DIR/install/* $INSTALL_DIR/"
        echo "  sudo systemctl restart $SERVICE_NAME"
        exit 1
    fi
}

step_start_service() {
    print_step "Starting service"

    systemctl start "$SERVICE_NAME"
    sleep 2

    if systemctl is-active --quiet "$SERVICE_NAME"; then
        echo "   ✅ Service started"
    else
        print_error "Service failed to start!"
        echo ""
        echo "Check logs:"
        echo "  fossibot-bridge-ctl logs 50"
        echo ""
        echo "To rollback:"
        echo "  sudo cp -r $BACKUP_DIR/install/* $INSTALL_DIR/"
        echo "  sudo systemctl restart $SERVICE_NAME"
        exit 1
    fi
}

step_cleanup_backup() {
    print_step "Cleanup"

    read -p "Remove backup? ($BACKUP_DIR) (y/N): " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        rm -rf "$BACKUP_DIR"
        echo "   ✅ Backup removed"
    else
        echo "   ℹ️  Backup kept: $BACKUP_DIR"
        echo "   Remove manually: sudo rm -rf $BACKUP_DIR"
    fi
}

# =============================================================================
# MAIN
# =============================================================================

main() {
    print_header "Fossibot MQTT Bridge - Upgrade"

    echo "This will upgrade the installation at:"
    echo "  • $INSTALL_DIR"
    echo ""
    echo "Your configuration will be preserved."
    echo ""
    read -p "Continue? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Upgrade aborted"
        exit 0
    fi

    check_root
    check_installation
    check_jq

    step_stop_service
    step_backup
    step_show_config_diff
    step_update_application
    step_update_control_script
    step_update_service_file
    step_validate_config
    step_start_service
    step_cleanup_backup

    print_header "Upgrade Complete! 🎉"

    echo "Service is running with upgraded code."
    echo ""
    echo "Check status:"
    echo "  fossibot-bridge-ctl status"
    echo ""
    echo "View logs:"
    echo "  fossibot-bridge-ctl logs"
    echo ""
}

main "$@"
