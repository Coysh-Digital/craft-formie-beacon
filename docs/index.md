---
layout: home

hero:
  name: Formie Beacon CRM
  text: Beacon CRM integration for Formie
  tagline: Send Craft CMS form submissions straight into your Beacon database, mapped to any record type you like.
  actions:
    - theme: brand
      text: Get started
      link: /installation
    - theme: alt
      text: Connecting to Beacon
      link: /connecting

features:
  - title: Reads your account, not a fixed list
    details: The record types and fields you map to are read live from your own Beacon account, so your custom record types and custom fields are there from the start.
  - title: Any record type
    details: Map to Person, Organisation, Payment, Event attendee, or any custom record type you have built. Not just contacts.
  - title: Create or update
    details: Match on a field of your choice so returning visitors update their existing record instead of creating a duplicate.
  - title: Correct shapes, automatically
    details: Names, emails, phone numbers, drop-downs, record links and currency amounts are all converted into the exact JSON Beacon expects.
---

## What it is

This plugin adds [Beacon CRM](https://beaconcrm.org) as a CRM integration for
[Formie](https://verbb.io/craft-plugins/formie). Once it is connected, any
Formie form can create or update records in your Beacon database when someone
submits it.

Most CRM integrations ship with a fixed idea of what your CRM contains, usually
a contact and maybe a company. Beacon does not work that way: every account has
its own record types and its own custom fields, and they change as you build
your database out. So instead of hard-coding a schema, this plugin asks your
account what it contains and builds the mapping screen from the answer. If you
add a custom record type or a new field in Beacon, hit **Refresh** and it is
there.

## What you get

- **Your account's real schema.** Record types, fields, labels and drop-down
  options all come from your Beacon account, including custom `c_*` fields.
- **Any record type.** Anything Beacon exposes, custom types included.
- **Exact drop-down values.** Pick from the values configured on the field
  rather than typing them and hoping they match.
- **Create or update.** Optionally match on a field such as email so repeat
  submissions update the existing record. See
  [Creating and updating records](/creating-and-updating).
- **The right JSON shapes.** Person names, email and phone arrays, drop-downs,
  record links and currency objects are all built for you. See
  [How field types are handled](/field-types).
- **Opt-in support.** Use Formie's opt-in field so data is only sent when
  someone actually consents.

## Where to next

- New here? Start with [Installation](/installation), then
  [Connecting to Beacon](/connecting).
- Ready to set up a form? See [Mapping form fields](/field-mapping).
- Worried about duplicates? See
  [Creating and updating records](/creating-and-updating).
- Something not saving? See [Troubleshooting](/troubleshooting).

::: warning Beacon workflows can fire on API writes
Records created by a form are treated like any other record, so an active
Beacon workflow can email someone or create a task in response. Check your
workflows before you put a form live.
:::
