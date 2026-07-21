# Mapping form fields

Mapping is done per form, so different forms can write to different record
types with completely different mappings.

## Enable the integration on a form

1. Edit your form and open the **Integrations** tab.
2. Select **Beacon** and turn it on.

## Choose a record type

**Record Type** lists every record type in your Beacon account, in alphabetical
order. That includes the built-in ones such as Person, Organisation and
Payment, and any custom record types you have created.

The list is read from your account when you open the tab. If you have just
added a record type in Beacon and it is not showing, click **Refresh
Integration**.

Changing the record type changes the fields available below it, since every
record type has its own.

## Map the fields

**Field Mapping** lists every writable field on the record type you chose, with
its Beacon label. For each one you can:

- Map it to a form field, so the submitted value is sent.
- Type a fixed value, which is sent on every submission. Useful for things like
  a **Source** field where you always want "Website contact form".
- Leave it empty, in which case the field is not included at all.

Only fields you actually map are sent. Empty values are skipped, so a blank
optional field will not wipe out data already held in Beacon.

### Drop-down fields

Drop-down fields show the exact options configured on that field in Beacon,
so you pick a real value rather than typing one. Beacon rejects any value that
is not configured for the field, so this saves a lot of failed submissions.

If you map a drop-down to a form field instead of picking a fixed value, make
sure the values your form submits match the Beacon options **exactly**,
including capitalisation.

### Person names

A person's name is a structured object in Beacon, so it appears as several
rows: **Full**, **First**, **Last**, **Middle** and **Prefix**.

Map the parts you have. If you map First and Last but not Full, the plugin
builds Full for you, because that is the version Beacon shows throughout its
interface. Formie's own Name field exposes its parts, so map
`Name (First)` to the first-name subfield and so on.

### Record links

Fields that link to another record, such as **Organisation** or **City**,
expect the numeric ID of an existing Beacon record. The plugin does not look
records up by name, so your form has to supply the ID, typically from a hidden
field or a drop-down whose values are Beacon IDs.

## Only send data when someone consents

**Opt-In Field** lets you nominate a field that controls whether anything is
sent at all. If the person leaves it empty, the submission is skipped entirely.

This is normally an Agree field, such as "Yes, please add me to your mailing
list". It is the cleanest way to keep marketing consent honest.

## Refreshing the schema

Click **Refresh Integration** whenever you change your Beacon database, for
example after adding a field, adding a drop-down option or creating a record
type. The plugin stores the schema it last read, so new fields will not appear
until you refresh.

::: tip Fields that are missing on purpose
Some Beacon fields never appear in the mapping list, including file uploads,
addresses, and any field Beacon calculates for itself. See
[How field types are handled](/field-types) for the full list and the reasons.
:::
