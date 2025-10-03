# Phase 5: Installation Scripts

**Time**: 2h 0min
**Priority**: P0
**Dependencies**: Phase 3 (PID), Phase 4 (Control Script)

---

## Goal

Erstelle Installations-Scripts für:
- **install.sh**: Komplettes Production Setup (User, Directories, Service, Config)
- **uninstall.sh**: Sauberes Entfernen aller Komponenten
- **upgrade.sh**: In-Place-Update mit Config-Preservation und Diff-Anzeige

**Target**: Ubuntu 24.04 LTS Server

---

## Steps

### Step 1: install.sh - Complete Installation Script (60min)

**File**: `scripts/install.sh`
**Lines**: New file

```bash
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
```

**Done when**: install.sh completes full production installation

**Commit**: `feat(scripts): add production installation script`

---

### Step 2: uninstall.sh - Clean Removal (30min)

**File**: `scripts/uninstall.sh`
**Lines**: New file

```bash
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
```

**Done when**: uninstall.sh cleanly removes all components with optional preservation

**Commit**: `feat(scripts): add uninstallation script with preserve options`

---

### Step 3: upgrade.sh - In-Place Update with Config Diff (30min)

**File**: `scripts/upgrade.sh`
**Lines**: New file

```bash
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
```

**Done when**: upgrade.sh performs in-place update with config diff and rollback support

**Commit**: `feat(scripts): add upgrade script with config diff and rollback`

---

## Validation Checklist

After completing all steps, verify:

- ✅ `install.sh` creates all directories with correct permissions
- ✅ `install.sh` installs systemd service
- ✅ `install.sh` installs control script to /usr/local/bin
- ✅ `uninstall.sh` removes all components
- ✅ `uninstall.sh --preserve-config` keeps configuration
- ✅ `upgrade.sh` shows config diff
- ✅ `upgrade.sh` performs in-place update
- ✅ All scripts have proper error handling

---

## Manual Testing

### Test Installation

```bash
# Run installer
cd /path/to/fossibot-php2
sudo scripts/install.sh

# Verify directories
ls -la /opt/fossibot-bridge
ls -la /etc/fossibot
ls -la /var/log/fossibot
ls -la /var/lib/fossibot

# Verify service
systemctl status fossibot-bridge

# Verify control script
which fossibot-bridge-ctl
fossibot-bridge-ctl --help

# Edit config
sudo nano /etc/fossibot/config.json
# (add credentials)

# Start service
fossibot-bridge-ctl start
fossibot-bridge-ctl status
```

### Test Upgrade

```bash
# Make code changes in dev environment
# Commit changes

# Run upgrade
sudo scripts/upgrade.sh

# Check for config diff output
# Verify service restarted successfully
fossibot-bridge-ctl status
```

### Test Uninstallation

```bash
# Preserve config
sudo scripts/uninstall.sh --preserve-config --preserve-logs

# Verify files removed
ls -la /opt/fossibot-bridge  # Should not exist
ls -la /etc/fossibot         # Should exist (preserved)

# Complete removal
sudo scripts/uninstall.sh

# Verify everything gone
ls -la /opt/fossibot-bridge  # Should not exist
ls -la /etc/fossibot         # Should not exist
```

---

## Troubleshooting

### Permission Denied during install

**Cause**: Not running as root

**Fix**:
```bash
sudo scripts/install.sh
```

### Missing jq in upgrade.sh

**Install**:
```bash
sudo apt-get install jq
```

### Service fails to start after upgrade

**Check logs**:
```bash
fossibot-bridge-ctl logs 100
```

**Rollback**:
```bash
sudo cp -r /tmp/fossibot-backup-*/install/* /opt/fossibot-bridge/
sudo systemctl restart fossibot-bridge
```

### Config diff shows unexpected changes

**Review manually**:
```bash
diff -u /etc/fossibot/config.json config/example.json
```

**Merge new options**:
```bash
sudo nano /etc/fossibot/config.json
# Add new keys from example.json
```

---

## Production Deployment Workflow

```bash
# 1. Fresh Installation
git clone https://github.com/youruser/fossibot-php2
cd fossibot-php2
composer install --no-dev
sudo scripts/install.sh

# 2. Configure
sudo nano /etc/fossibot/config.json
fossibot-bridge-ctl validate

# 3. Start
fossibot-bridge-ctl start
fossibot-bridge-ctl status

# 4. Later: Upgrade
git pull
composer install --no-dev
sudo scripts/upgrade.sh
```

---

## File Permissions Summary

| Path | Owner | Perms | Reason |
|------|-------|-------|--------|
| `/opt/fossibot-bridge/` | root:root | 755 | Read-only application |
| `/etc/fossibot/` | root:fossibot | 750 | Config readable by service |
| `/etc/fossibot/config.json` | root:fossibot | 640 | Credentials protected |
| `/var/log/fossibot/` | fossibot:fossibot | 755 | Writable for logs |
| `/var/lib/fossibot/` | fossibot:fossibot | 755 | Writable for cache |
| `/var/run/fossibot/` | fossibot:fossibot | 755 | Writable for PID |

---

## Next Steps

After Phase 5 completion:
- **Phase 6**: systemd Service Enhancement (RuntimeDirectory, etc.)
- **Phase 7**: Documentation (INSTALL.md, UPGRADE.md, TROUBLESHOOTING.md)

---

**Phase 5 Complete**: Production installation, upgrade, and uninstall scripts fully functional.
