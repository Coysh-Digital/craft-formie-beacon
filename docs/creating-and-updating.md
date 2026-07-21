# Creating and updating records

By default every submission creates a **new** Beacon record. On a public form
that means the same person filling the form in twice leaves you with two
records to merge later.

**Update Existing Records** avoids that. With it on, the plugin asks Beacon to
find a matching record first and update it, only creating a new one if nothing
matches. Beacon calls this an upsert.

## Turning it on

1. On the form's **Integrations → Beacon** tab, turn on **Update Existing
   Records**.
2. Choose a **Match On** field.
3. Make sure that same field is mapped in **Field Mapping**.

That last step matters. Beacon needs the value in the submission itself to know
what to search for, so if the match field is not mapped, nothing is sent and
the failure is logged against the submission.

## Choosing a match field

**Email** is the usual choice for people. It is the field most likely to be
present, and most likely to be unique.

Whatever you choose, it needs to be genuinely unique in your database. If two
records share the value, Beacon picks one, and it may not be the one you
expected.

If you are importing from another system and have a legacy ID stored on each
record, that is a better match field than email, because a person's email
address can change while their legacy ID cannot.

::: warning Check for existing duplicates first
Upserting into a database that already contains duplicates does not clean them
up. It just updates whichever record Beacon matches, which can make the
duplication harder to spot. Deduplicate first if you can.
:::

## What gets overwritten

Only the fields you map are sent, and empty values are skipped. So a mapped
field left blank on the form will not blank out a value already in Beacon.

A mapped field that **does** have a value will overwrite what is there. That is
usually what you want, but it is worth thinking about for fields your team
maintains by hand. If a staff member has carefully set someone's **Type** and
your form maps Type to a fixed value, the form wins every time.

If in doubt, map fewer fields.

## Create-only, deliberately

Leaving **Update Existing Records** off is the right call when each submission
genuinely is a separate thing: an event registration, a donation, a support
case, an order. In those cases you want a new record every time, and matching
would actively lose data.
