#!/bin/bash
# ABOUTME: Uninstallation script for Fossibot MQTT Bridge
# Removes all installed components, optionally preserves config/logs

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
LOG_DIR="/var/log/fossibot"
CACHE_DIR="/var/lib/fossibot"
SERVICE_NAME="fossibot-bridge"
SERVICE_USER="fossibot"
CONTROL_SCRIPT="/usr/local/bin/fossibot-bridge-ctl"

# Flags
PRESERVE_CONFIG=false
PRESERVE_LOGS=false

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

parse_args() {
    while [[ $# -gt 0 ]]; do
        case $1 in
            --preserve-config)
                PRESERVE_CONFIG=true
                shift
                ;;
            --preserve-logs)
                PRESERVE_LOGS=true
                shift
                ;;
            --help|-h)
                echo "Usage: $0 [options]"
                echo ""
                echo "Options:"
                echo "  --preserve-config   Keep configuration files"
                echo "  --preserve-logs     Keep log files"
                echo "  --help              Show this help"
                exit 0
                ;;
            *)
                print_error "Unknown option: $1"
                exit 1
                ;;
        esac
    done
}

# =============================================================================
# UNINSTALLATION STEPS
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

step_disable_service() {
    print_step "Disabling service"

    if systemctl is-enabled --quiet "$SERVICE_NAME" 2>/dev/null; then
        systemctl disable "$SERVICE_NAME"
        echo "   ✅ Service disabled"
    else
        echo "   ℹ️  Service not enabled"
    fi
}

step_remove_service_file() {
    print_step "Removing systemd service file"

    local service_file="/etc/systemd/system/$SERVICE_NAME.service"

    if [ -f "$service_file" ]; then
        rm -f "$service_file"
        systemctl daemon-reload
        echo "   ✅ Service file removed"
    else
        echo "   ℹ️  Service file not found"
    fi
}

step_remove_application() {
    print_step "Removing application directory"

    if [ -d "$INSTALL_DIR" ]; then
        rm -rf "$INSTALL_DIR"
        echo "   ✅ $INSTALL_DIR removed"
    else
        echo "   ℹ️  Directory not found"
    fi
}

step_remove_config() {
    if [ "$PRESERVE_CONFIG" = true ]; then
        print_warn "Preserving configuration: $CONFIG_DIR"
    else
        print_step "Removing configuration"

        if [ -d "$CONFIG_DIR" ]; then
            rm -rf "$CONFIG_DIR"
            echo "   ✅ $CONFIG_DIR removed"
        else
            echo "   ℹ️  Directory not found"
        fi
    fi
}

step_remove_logs() {
    if [ "$PRESERVE_LOGS" = true ]; then
        print_warn "Preserving logs: $LOG_DIR"
    else
        print_step "Removing logs"

        if [ -d "$LOG_DIR" ]; then
            rm -rf "$LOG_DIR"
            echo "   ✅ $LOG_DIR removed"
        else
            echo "   ℹ️  Directory not found"
        fi
    fi
}

step_remove_cache() {
    print_step "Removing cache"

    if [ -d "$CACHE_DIR" ]; then
        rm -rf "$CACHE_DIR"
        echo "   ✅ $CACHE_DIR removed"
    else
        echo "   ℹ️  Directory not found"
    fi
}

step_remove_runtime() {
    print_step "Removing runtime directory"

    if [ -d "/var/run/fossibot" ]; then
        rm -rf "/var/run/fossibot"
        echo "   ✅ /var/run/fossibot removed"
    fi
}

step_remove_control_script() {
    print_step "Removing control script"

    if [ -f "$CONTROL_SCRIPT" ]; then
        rm -f "$CONTROL_SCRIPT"
        echo "   ✅ $CONTROL_SCRIPT removed"
    else
        echo "   ℹ️  Control script not found"
    fi
}

step_remove_user() {
    print_step "Removing system user"

    if id "$SERVICE_USER" &>/dev/null; then
        userdel "$SERVICE_USER"
        echo "   ✅ User $SERVICE_USER removed"
    else
        echo "   ℹ️  User not found"
    fi
}

# =============================================================================
# MAIN
# =============================================================================

main() {
    parse_args "$@"

    print_header "Fossibot MQTT Bridge - Uninstallation"

    echo "This will remove:"
    echo "  • Application:     $INSTALL_DIR"
    echo "  • Configuration:   $CONFIG_DIR $([ "$PRESERVE_CONFIG" = true ] && echo "(PRESERVED)" || echo "(REMOVED)")"
    echo "  • Logs:            $LOG_DIR $([ "$PRESERVE_LOGS" = true ] && echo "(PRESERVED)" || echo "(REMOVED)")"
    echo "  • Cache:           $CACHE_DIR"
    echo "  • Service:         $SERVICE_NAME"
    echo "  • User:            $SERVICE_USER"
    echo "  • Control Script:  $CONTROL_SCRIPT"
    echo ""
    read -p "Continue? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Uninstallation aborted"
        exit 0
    fi

    check_root

    step_stop_service
    step_disable_service
    step_remove_service_file
    step_remove_application
    step_remove_config
    step_remove_logs
    step_remove_cache
    step_remove_runtime
    step_remove_control_script
    step_remove_user

    print_header "Uninstallation Complete"

    if [ "$PRESERVE_CONFIG" = true ] || [ "$PRESERVE_LOGS" = true ]; then
        echo "Preserved files:"
        [ "$PRESERVE_CONFIG" = true ] && echo "  • Config: $CONFIG_DIR"
        [ "$PRESERVE_LOGS" = true ] && echo "  • Logs: $LOG_DIR"
        echo ""
        echo "To remove these manually:"
        [ "$PRESERVE_CONFIG" = true ] && echo "  sudo rm -rf $CONFIG_DIR"
        [ "$PRESERVE_LOGS" = true ] && echo "  sudo rm -rf $LOG_DIR"
    fi
}

main "$@"
