# Changelog

All notable changes to `westel/laravel-license` will be documented in this file.

## [1.0.0] - 2025-01-12

### Added
- Initial release of Westel Laravel License package
- Dual mode support (server and client)
- Server mode features:
  - License management and validation
  - Hardware fingerprinting and activation tracking
  - JWT offline token generation
  - Feature gating and access control
  - Grace period handling
  - Heartbeat validation
  - Comprehensive API endpoints (validate, activate, deactivate, heartbeat, features, etc.)
  - License activation limits
  - Validation logging and analytics
- Client mode features:
  - HTTP client for license server communication
  - Intelligent caching system
  - Offline validation using JWT tokens
  - Auto-generated hardware fingerprints
  - Retry mechanism for failed requests
  - Graceful degradation during server downtime
  - Feature validation and limit checking
  - Event dispatching (LicenseValidated, LicenseExpired, etc.)
- Hardware fingerprinting service:
  - Cross-platform support (Windows, Linux, macOS)
  - Multiple fingerprint components (hostname, IP, MAC, CPU, disk serial)
  - Tolerance-based fingerprint comparison
  - Cached fingerprint generation
- Eloquent models:
  - License
  - Product
  - LicenseActivation
  - LicenseValidation
  - ProductFeatureAssignment
  - FeatureDefinition
- Middleware:
  - EnsureLicenseValid - Validates license before route access
  - CheckFeatureAccess - Validates feature availability
  - EnsureServerMode - Protects server-only routes
- Console commands:
  - `license:check` - Display license status and information
  - `license:install` - Interactive package installation and configuration
- Blade directives:
  - `@license('feature_key')` - Check feature availability
  - `@licenseValid` - Check if license is valid
  - `@licenseExpired` - Check if license is expired
  - `@licenseGracePeriod` - Check if in grace period
- Facade support for static access
- Comprehensive configuration options
- Database migrations for server mode
- Event system for license lifecycle
- Exception hierarchy for error handling
- Complete documentation and usage examples

### Security
- SHA-256 hardware fingerprint hashing
- JWT token signing with HS256/RS256
- CORS support for API endpoints
- Rate limiting for API requests
- Secure hardware fingerprint validation
- Grace period for expired licenses

## [Unreleased]

### Planned Features
- RSA key pair support for JWT signing
- Multi-tenancy support
- License transfer functionality
- Subscription integration
- Webhook notifications
- Dashboard UI components
- License usage analytics
- Automated license renewal
- License key generation customization
- Advanced reporting and insights
