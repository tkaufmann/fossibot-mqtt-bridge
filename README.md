# Fossibot PHP Library

PHP-Bibliothek zur Steuerung von Fossibot-Geräten über die Cloud-API.

## MQTT Bridge Daemon

Der Daemon verbindet Fossibot Cloud mit einem lokalen MQTT-Broker.

**Funktionen:**
- Multi-Account-Unterstützung
- ReactPHP Event Loop (non-blocking I/O)
- Standard-MQTT mit JSON-Payloads
- Automatische Wiederverbindung
- systemd-Integration
- Strukturiertes Logging

### Installation

```bash
composer install
cp config/example.json config/config.json
nano config/config.json
php daemon/fossibot-bridge.php --config config/config.json
```

**systemd Service:**

```bash
cd daemon
sudo ./install-systemd.sh
sudo nano /etc/fossibot/config.json
sudo systemctl enable --now fossibot-bridge
sudo systemctl status fossibot-bridge
```

### MQTT Topics

**Device State:**
```
fossibot/{mac}/state
{"soc": 85.5, "inputWatts": 450, "outputWatts": 120, "usbOutput": true, ...}
QoS: 1, Retained
```

**Device Commands:**
```
fossibot/{mac}/command
{"action": "usb_on"}
QoS: 1
```

**Bridge Status:**
```
fossibot/bridge/status
{"status": "online", "version": "2.0.0", ...}
QoS: 1, Retained
```

### Dokumentation

`docs/daemon/`:
- [00-OVERVIEW.md](docs/daemon/00-OVERVIEW.md) - Architektur-Übersicht
- [01-ARCHITECTURE.md](docs/daemon/01-ARCHITECTURE.md) - Technisches Design
- [02-TOPICS-MESSAGES.md](docs/daemon/02-TOPICS-MESSAGES.md) - MQTT-Protokoll
- [DEPLOYMENT.md](daemon/DEPLOYMENT.md) - Production-Deployment

### Integration

`examples/`:
- Home Assistant YAML
- Node-RED Flows
- IP-Symcon PHP
- Python MQTT Client

### Entwicklung

Implementierungs-Phasen:
1. [Phase 0](docs/daemon/03-PHASE-0-SETUP.md) - Setup & Dependencies
2. [Phase 1](docs/daemon/04-PHASE-1-CLIENT.md) - AsyncCloudClient
3. [Phase 2](docs/daemon/05-PHASE-2-BRIDGE.md) - MqttBridge
4. [Phase 3](docs/daemon/06-PHASE-3-RECONNECT.md) - Reconnect-Logik
5. [Phase 4](docs/daemon/07-PHASE-4-CLI.md) - CLI & systemd

Testing: [09-TESTING.md](docs/daemon/09-TESTING.md)

## License

MIT
