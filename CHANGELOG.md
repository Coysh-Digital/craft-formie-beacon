# Release Notes for Formie Beacon CRM Integration

## 1.0.0 - Unreleased

### Added
- Initial release.
- Beacon CRM integration for Formie, supporting any record type in your account.
- Schema, fields and drop-down options are read live from the Beacon API, so custom record types and custom fields are supported without configuration.
- Optional upsert on a configurable match field, to avoid duplicate records.
- Fixed values, for sending a constant value to any field without a matching form field. Drop-downs offer their configured options.
- Beacon record IDs are logged on success, for reconciliation.
- Failed writes log Beacon's underlying validation message, which its API otherwise buries beneath a generic error, along with the payload sent.
