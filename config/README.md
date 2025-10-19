# Configuration

## Files

### `example.json` - Development Configuration
Template for **local development** with relative paths.

**Usage:**
```bash
cp config/example.json config/config.json
# Edit config/config.json with your credentials
php daemon/fossibot-bridge.php --config config/config.json
```

### `production.example.json` - Production/Docker Configuration
Template for **production deployment** with absolute paths.

**Usage:**
```bash
# Copy to your production location
cp config/production.example.json /srv/docker/fossibot/mounts/fossibot/config.json
# Edit with production credentials and settings
```

---

## ⚠️ Required Sections for Production

### `health` - Docker Health Checks
**Required** for Docker to monitor container health:
```json
"health": {
  "enabled": true,
  "port": 8080
}
```

Without this section, Docker health checks will fail with "Connection refused" and the container will be marked as `unhealthy`.

### `cache` - Token Persistence
**Required** for efficient token caching:
```json
"cache": {
  "directory": "/var/lib/fossibot",
  "token_ttl_safety_margin": 300,
  "max_token_ttl": 86400,
  "device_list_ttl": 86400,
  "device_refresh_interval": 86400
}
```

**Important settings:**
- `max_token_ttl`: Maximum TTL for any cached token (default: 86400s = 1 day). Caps unrealistic JWT expiry claims.
  - Fossibot's S2 login token claims 10-year expiry but is invalidated server-side much sooner
  - Without this cap, the bridge would cache tokens for 10 years and fail when they're invalidated
  - With 1-day cap, the bridge re-authenticates daily, preventing stale token issues

Without this section, the bridge will re-authenticate on every restart instead of using cached tokens.

---

## Validation

Test your config before starting:
```bash
php daemon/fossibot-bridge.php --config config/config.json --validate
```

## Configuration Options

### accounts

Array of Fossibot account credentials.

- `email` (string, required): Fossibot account email
- `password` (string, required): Fossibot account password
- `enabled` (bool, optional): Set to false to disable account (default: true)

### mosquitto

Local MQTT broker connection settings.

- `host` (string): Broker hostname (default: localhost)
- `port` (int): Broker port (default: 1883)
- `username` (string|null): Auth username (null = no auth)
- `password` (string|null): Auth password
- `client_id` (string): MQTT client ID (default: fossibot_bridge)

### daemon

Daemon process settings.

- `log_file` (string): Path to log file
- `log_level` (string): Log level (debug, info, warning, error)

### bridge

Bridge behavior settings.

- `status_publish_interval` (int): Seconds between status publishes (default: 60)
- `reconnect_delay_min` (int): Initial reconnect delay in seconds (default: 5)
- `reconnect_delay_max` (int): Maximum reconnect delay in seconds (default: 60)

## Security

⚠️ **IMPORTANT:** Keep `config/config.json` private!
- Contains passwords in plaintext
- Add to `.gitignore` (already done)
- Use restrictive file permissions: `chmod 600 config/config.json`