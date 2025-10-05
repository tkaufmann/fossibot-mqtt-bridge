# ABOUTME: Custom Mosquitto image with default configuration
FROM eclipse-mosquitto:2

# Copy default config (no chown needed - read-only mount handles this)
COPY config/mosquitto.conf /mosquitto/config/mosquitto.conf
