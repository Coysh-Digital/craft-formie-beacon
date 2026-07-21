# Connecting to Beacon

Before any form can send data, the plugin needs to know which Beacon account to
talk to and how to authenticate with it. You only do this once.

## Create an API key in Beacon

In Beacon, go to **Settings → API keys** and create a key.

Two things to know:

- Only an administrator can create an API key.
- The key is shown **once**, at the moment you create it. Copy it straight away.
  If you lose it, revoke it and make a new one.

## Find your account ID

Your account ID is the number in your Beacon API URL:

```
https://api.beaconcrm.org/v1/account/32244
                                     ^^^^^
```

## Store the credentials

Add both values to your `.env` file:

```
BEACON_ACCOUNT_ID=32244
BEACON_API_KEY=your-secret-key
```

::: warning Keep the key out of project config
Always reference these as environment variables rather than pasting the key
into the settings field. Craft stores integration settings in project config,
which is committed to version control, so a pasted key ends up in your git
history and in every environment.
:::

## Add the integration

1. Go to **Formie → Settings → CRM**.
2. Click **New integration** and choose **Beacon**.
3. Give it a name, such as "Beacon".
4. Set **Account ID** to `$BEACON_ACCOUNT_ID`.
5. Set **API Key** to `$BEACON_API_KEY`.
6. Save.

The `$` prefix tells Craft to read the value from your environment rather than
treating it as literal text.

## Check the connection

Back on the CRM settings screen, the integration shows a connection status.
Click **Refresh** to verify it.

A successful check means the plugin reached your account and could read its
schema. If it fails, see [Troubleshooting](/troubleshooting).

## What happens next

Connecting only proves the credentials work. Nothing is sent to Beacon until
you enable the integration on a specific form and map some fields, which is
covered in [Mapping form fields](/field-mapping).
