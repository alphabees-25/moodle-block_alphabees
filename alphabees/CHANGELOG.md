# Changelog

All notable changes to the Alphabees AI Tutor Moodle block plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [3.0.0] - 2026-05-10

### Added
- First stable 3.x release.
- Automatic Moodle site registration with the Alphabees backend after saving the API key.
- Signed backend communication using locally generated Ed25519 keys, site identifiers, and nonce replay protection.
- Optional Alphabees web-services integration with automatic service user creation, role assignment, token generation, and teardown.
- Portal-managed placement synchronization for Alphabees blocks.
- Scheduled tasks for site registration, placement sync, outbound retry processing, web-service token posting, placement lifecycle events, and nonce cleanup.
- Retry queue for transient outbound backend communication failures.
- Backend course writing/export support and REST connection endpoint.
- Status and diagnostics panel for backend connection and web-services state.

### Changed
- Updated supported Moodle range to 4.1 LTS through 5.2.
- Updated plugin version to `2026051001` and release label to `3.0.0`.
- Updated portal references to `portal.alphalearn.ai`.
- Updated chat widget and mobile app configuration for the current Alphabees backend.

### Fixed
- Corrected GitHub README links and screenshot references for the current public repository.

---

## [2.0.3] - 2025-10-14

### Fixed
- Bug fix in AMD build (JavaScript module)
- Version number update

---

## [2.0.1] - 2025-10-09

### Added
- Moodle 5.1 support added to supported versions list

### Changed
- Updated sidebar text
- Bumped plugin version to `2025100901`

---

## [2.0.0] - 2025-09-03

### Added
- Mobile app support
- Moodle 5 compliance

### Changed
- Cleaned up repository (removed unwanted files, updated `.gitignore`)
- Updated README

### Fixed
- Missing language strings added
- Privacy API implementation corrected
- Various Moodle compliance issues resolved
- Gruntfile fixed
- Node modules properly excluded from tracking
- Image references corrected

---

## [1.0.1] - 2025-01-28

### Changed
- Updated widget version
- Added screenshots to README

### Fixed
- Moodle flags corrected
- Image reference fixed
- Added README to install ZIP package

---

## [1.0.0] - 2024-12-11

### Added
- Initial release of the Alphabees AI Tutor block plugin
- Integration of AI tutor chat widget into Moodle via block structure
- API key configuration in admin settings
- Tutor selection via block instance settings
- WebSocket-driven real-time chat communication
- Multi-language support (English and German)
- Moodle code guidelines compliance
- GNU GPL v3.0 license
