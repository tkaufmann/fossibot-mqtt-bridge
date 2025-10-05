# ABOUTME: Custom Mosquitto image with default configuration
FROM eclipse-mosquitto:2

# Copy default config
COPY config/mosquitto.conf /mosquitto/config/mosquitto.conf

# Set proper permissions
RUN chown -R mosquitto:mosquitto /mosquitto/config
