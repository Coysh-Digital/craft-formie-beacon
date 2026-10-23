# Release Notes for Formie Beacon CRM Integration

## 1.4.0 - 2026-10-23

### Added
- **Additional Records.** One submission can now write several records, such as a Person, an Event attendee and a Payment. The existing settings are the main record; below them, **Additional Records** adds more, each with its own record type, field mapping, fixed values, linked records and optional upsert. They are written in order after the main record.
- **Record from this submission.** In Linked Records, **Match On** now also offers the main record and every additional record above the current one, so an attendee can link to the person written just before it. Nothing is looked up, so **Look Up From** can stay empty.
- **Payment values.** Plain fields can be mapped from the submission's payment: amount, currency, reference, status, date and provider. They are shaped like any other value, so an amount mapped to a currency field arrives as a currency.
- **Only If Payment Succeeded**, on each record, to write it only when the submission has a successful payment.

### Changed
- A record that fails no longer stops the rest. Records that link to it are skipped; the others are still written, and the integration is reported as failed. A record skipped on purpose, such as a payment record with no payment, is not a failure.
- Log messages for a failed or empty record now say which record they are about.

### Notes
- Forms saved before this release need no changes. The main record's settings are stored exactly where they were.
- Retrying a submission writes every record again. Turn on **Update Existing Records** for the main record so a retry does not duplicate the person.
- Settings cached before this release do not list payment values until you click **Refresh Integration**.

## 1.3.0 - 2026-08-17

### Added
- **Linked Records.** Fields that point at another record store a numeric Beacon record ID, which a form never has. A new table on the form's integration settings maps the form field where someone types a church, branch or city name, picks the field on the other record type to find it by, and optionally creates the record when nothing matches. Matching ignores capitalisation.
- **Also Add To**, which adds the record just written to a list on the record it linked to — the church-admins direction, where the field lives on the Organisation rather than the Person. It appends rather than replacing, so existing entries survive.

### Changed
- Requires [coyshdigital/beaconcrm-php](https://github.com/Coysh-Digital/beaconcrm-php) 1.2, which adds the linking and lookup support this is built on.
- Record links are no longer documented as needing a Beacon ID supplied by the form. Mapping one directly still works for forms that do have IDs, and takes second place to a Linked Records row for the same field.
- Lookups are sent by the library rather than through Formie's `deliverPayload()`, because finding or creating a record is not a single describable request. The submission itself is still written through Formie, so payload events, proxy settings and submission logging are unaffected.

### Notes
- **Beacon's Relationships feature cannot be set through the API**, so this plugin cannot touch it. Relationship types such as Trustee, their reciprocal sides and their dates are unavailable to any integration; probing a live account confirms there is no endpoint. Use a point-to-another-record field instead.
- A failed lookup never costs the submission. The record is written without the link and the reason is logged, including the value that was searched for.
- **Create If Missing** on a free-text field will make a record for every spelling variation someone types. Prefer a drop-down of real values, or leave creation off and read the log.
- A single-valued field on the target record can be filled by **Also Add To** when it is empty, but not added to once taken. It is left alone rather than overwritten.

## 1.2.0 - 2026-08-05

### Added
- Address fields can now be mapped. They appear in the mapping list one part at a time — **Address (City)**, **Address (Postal code)** and so on — in the same way person names already do, and are reassembled into a single address when the submission is sent.

### Changed
- Requires [coyshdigital/beaconcrm-php](https://github.com/Coysh-Digital/beaconcrm-php) 1.1, which adds location support. Beacon models an address as a contact point — a list of objects, like emails and phones — rather than the single object previously assumed.

### Notes
- Mapping fills in the record's first address, and a write replaces the whole address list rather than adding to it. A record needing several addresses has to be written through the API directly.

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
