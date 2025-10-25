# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

### Changed
- **Health endpoint**: Device online status now based on actual updates instead of API `mqtt_state`
  - Devices considered online if updates received within last 6 minutes
  - Status updated every 60 seconds
  - More reliable than Fossibot API's `mqtt_state` field which is often incorrect

### Fixed
- **Token caching**: Added `max_token_ttl` (default: 1 day) to cap token cache TTL regardless of JWT expiry
  - Fossibot's S2 login token claims 10-year expiry in JWT but is invalidated server-side sooner
  - Without cap, bridge would cache token for 10 years and fail when server invalidates it
  - With 1-day cap, bridge re-authenticates daily, preventing stale token issues
  - Config option: `cache.max_token_ttl` (seconds, default: 86400)
- **Health endpoint**: Device metrics now updated correctly after initial device discovery
  - Previously showed 0 devices due to race condition during startup

### Removed
- API `mqtt_state` transition tracking (unreliable due to intermittent API failures)

## [2.0.0] - 2025-10-19

### Added
- Docker health check endpoint (`/health` on port 8080)
- Token and device caching with configurable TTL
- Production configuration template (`config/production.example.json`)
- Section comments in large code files for better navigation
- Non-blocking async file handler for logging

### Removed
- Obsolete synchronous Connection.php (1,404 lines)
- 9 unused ValueObject files from old implementation
- Obsolete TODO comments

### Documentation
- Added production config template with required sections
- Improved config README with dev vs. production guidance
- Documented health endpoint requirement for Docker deployments

## [1.0.0] - 2025-10-06

Initial release with core functionality.

