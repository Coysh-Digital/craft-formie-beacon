# Installation

## Requirements

- Craft CMS 5.0 or later
- PHP 8.2 or later
- Formie 3.0 or later
- A Beacon account on a plan that includes API access

## Install with Composer

From your project root, require the plugin and then install it:

```bash
composer require coyshdigital/craft-formie-beacon
php craft plugin/install formie-beacon-crm
```

## Install from the Plugin Store

Or install it from the **Plugin Store** in your control panel: search for
"Formie Beacon CRM", then click **Install**.

## After installing

The plugin adds Beacon to Formie's list of CRM integrations. It does nothing on
its own until you connect it to your Beacon account, so head to
[Connecting to Beacon](/connecting) next.

::: tip Formie is a separate plugin
This plugin extends Formie rather than replacing any part of it. You need
Formie installed and licensed already. Composer will refuse to install without
it.
:::
