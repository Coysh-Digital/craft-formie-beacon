# Formie Beacon CRM Integration

A [Craft CMS](https://craftcms.com) plugin that adds [Beacon CRM](https://beaconcrm.org) as a CRM integration for [Formie](https://verbb.io/craft-plugins/formie), letting form submissions create or update records in your Beacon database.

## Features

- **Works with any Beacon account.** The plugin reads your account's schema from Beacon's API at runtime, so nothing is hard-coded. Your custom record types and custom `c_*` fields appear in the mapping UI automatically.
- **Any record type.** Map to Person, Organisation, Payment, or any custom record type you've created.
- **Exact drop-down values.** Options for drop-down fields are read from your account, so you pick from the configured values rather than typing them and hoping.
- **Create or update.** Optionally upsert on a field of your choice to avoid creating duplicate records from repeat submissions.

## Requirements

- Craft CMS 5.0.0+
- PHP 8.2+
- Formie 3.0+

## Installation

```bash
composer require coyshdigital/craft-formie-beacon
php craft plugin/install formie-beacon-crm
```

## Setup

1. In Beacon, go to **Settings → API keys** and create a key. It is shown once, so copy it immediately. Only an administrator can create one.
2. Add the key to your `.env`:

   ```
   BEACON_ACCOUNT_ID=32244
   BEACON_API_KEY=your-secret-key
   ```

3. In Craft, go to **Formie → Settings → CRM** and add a **Beacon** integration.
4. Set **Account ID** to `$BEACON_ACCOUNT_ID` and **API Key** to `$BEACON_API_KEY`. Your account ID is the number in your Beacon API URL: `https://api.beaconcrm.org/v1/account/32244`.
5. Save, then hit **Refresh** to verify the connection.

Storing credentials as environment variables keeps the API key out of your project config and out of version control.

## Per-form configuration

On a form, go to **Integrations → Beacon** and enable it. Then:

- **Record Type** — which Beacon record to create. The list is read from your account.
- **Update Existing Records** — when on, the plugin upserts instead of always creating. Pick a **Match On** field that holds a genuinely unique value; `Email` is the usual choice for people. That field must also be mapped.
- **Field Mapping** — map your form fields to Beacon fields.

Use **Opt-In Field** if you only want to send data when a user consents — typically an Agree field.

## How values are converted

Beacon expects specific shapes per field type, which the plugin handles for you:

| Beacon field type | What gets sent |
| --- | --- |
| Person name | An object; each name part is a separate mapping row and `Full` is derived if you only map the parts |
| Email | `[{ "email": "...", "is_primary": true }]` |
| Phone | `[{ "number": "...", "is_primary": true }]` |
| Drop-down | An array, even for single-select fields |
| Record link | An array of integer Beacon record IDs |
| Checkbox | A JSON boolean |
| Currency | `{ "value": 25.5 }` in major units, using your account's default currency. Beacon silently discards bare numbers here |
| Number, percent, rating | A JSON number |

## Known limitations

- **File upload fields are not supported.** Beacon requires a separate signed-upload handshake that cannot be performed inside the entity payload, so file fields are omitted from the mapping UI.
- **Location and address fields are not supported.** Beacon expects a structured address object that a single mapped form field cannot express.
- **Read-only fields are omitted.** Smart fields, rollup fields, and auto-increment fields are computed by Beacon and rejected on write.
- **Record links need Beacon record IDs.** To populate one, your form must supply the numeric ID of an existing Beacon record; the plugin does not look records up by name.
- **Beacon workflows can fire on API writes.** Before going live, check whether any active workflow will send communications or create tasks in response to records the form creates.

## Troubleshooting

Failed sends are logged with Beacon's own error message. Check **Formie → Submissions → (a submission) → Integrations**, or `storage/logs/formie.log`.

- `invalid_api_key` — the key was revoked or mistyped.
- A `400` naming a field usually means a drop-down value is not configured in Beacon, or a record link was given something other than a record ID.
- `429` means you have hit Beacon's rate limit: 300 requests per minute, or 60 for bulk operations.

## License

MIT
