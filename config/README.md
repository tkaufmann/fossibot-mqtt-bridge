# Configuration

## Setup

1. Copy example config:
   ```bash
   cp config/example.json config/config.json
   ```

2. Edit `config/config.json`:
   - Add your Fossibot account credentials
   - See `.env` for working test credentials
   - Multiple accounts: add more objects to `accounts` array

3. Test config:
   ```bash
   php test_config_load.php
   ```

## Development

For development, use credentials from `.env`:
```bash
FOSSIBOT_EMAIL=your-email@example.com
FOSSIBOT_PASSWORD=your-password
```

You can copy these into `config/config.json`.

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