# Release Notes for Formie Beacon CRM Integration

## 1.1.0 - 2026-07-22

### Added
- Dependency on [coyshdigital/beaconcrm-php](https://github.com/Coysh-Digital/beaconcrm-php) 1.0, a standalone Beacon CRM library that Composer installs alongside the plugin. Use it directly if you need to talk to Beacon from elsewhere in your project.

### Changed
- Moved all Beacon API work into that library: reading the account schema, deciding which fields can be written, shaping values into the JSON Beacon expects, and unpacking its errors. Formie still sends the requests, so integration logging, payload events and test mode behave exactly as before.
- An upsert can now match on a person-name field mapped only through its parts, rather than requiring the whole field.
- A mapped value that is an empty array is now skipped, like other empty values, instead of being sent and potentially clearing the field.

## 1.0.3 - 2026-07-21

### Changed
- Used a generic account ID in the documentation and settings hints, rather than a real one.

## 1.0.2 - 2026-07-21

### Changed
- Replaced the plugin icon with the Fusion torch mark, recoloured for the plugin.

## 1.0.1 - 2026-07-21

### Added
- Plugin icons, including a monochrome mask for the control panel navigation.

## 1.0.0 - 2026-07-21

### Added
- Initial release.
- Beacon CRM integration for Formie, supporting any record type in your account.
- Schema, fields and drop-down options are read live from the Beacon API, so custom record types and custom fields are supported without configuration.
- Optional upsert on a configurable match field, to avoid duplicate records.
- Fixed values, for sending a constant value to any field without a matching form field. Drop-downs offer their configured options.
- Beacon record IDs are logged on success, for reconciliation.
- Failed writes log Beacon's underlying validation message, which its API otherwise buries beneath a generic error, along with the payload sent.
