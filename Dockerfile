# ABOUTME: Multi-stage Docker build for Fossibot MQTT Bridge
#
# Stage 1: Build dependencies
FROM php:8.4-cli-alpine AS builder

# Install build dependencies
RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev

# Install PHP extensions
RUN docker-php-ext-install zip

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /app

# Copy dependency files
COPY composer.json composer.lock ./

# Install dependencies (production only, optimized)
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader

# Stage 2: Runtime image
FROM php:8.4-cli-alpine

# Install runtime dependencies and build tools for sockets/pcntl extensions
RUN apk add --no-cache \
    libzip \
    linux-headers \
    su-exec \
    && docker-php-ext-install sockets pcntl \
    && apk del linux-headers

# Create non-root user (default UID/GID 1000, can be changed via entrypoint)
RUN addgroup -g 1000 fossibot \
    && adduser -D -u 1000 -G fossibot fossibot

# Set working directory
WORKDIR /app

# Copy dependencies from builder
COPY --from=builder /app/vendor ./vendor

# Copy application files
COPY --chown=fossibot:fossibot src ./src
COPY --chown=fossibot:fossibot daemon ./daemon
COPY --chown=fossibot:fossibot config ./config
COPY --chown=fossibot:fossibot composer.json ./

# Create directories
RUN mkdir -p /var/lib/fossibot /var/log/fossibot

# Copy and setup entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Health check (calls bridge's health endpoint)
HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
    CMD php -r "echo file_get_contents('http://localhost:8080/health') ?: exit(1);"

# Expose health endpoint port
EXPOSE 8080

# Set entrypoint and default command
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "daemon/fossibot-bridge.php", "--config", "/etc/fossibot/config.json"]
