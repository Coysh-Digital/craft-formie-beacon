# Linking records

Beacon fields that point at another record — **Organisation** on a Person, a
**City**, a **Committed church** — store that record's numeric ID. A form never
has one. Someone filling in an enquiry types a church *name*.

**Linked Records** bridges the two. Tell it which form value identifies the
record and which field to find it by, and the plugin looks the record up before
sending, so the link is filled in properly.

## Setting one up

On the form's **Integrations → Beacon** tab, below the field mapping, you will
find **Linked Records**. It lists every field on your chosen record type that
links to another record. Each has two parts.

**Look Up From** is the form field whose value identifies the record — the one
where someone types their church, their university, their branch.

Then, in the table below it:

| Column | What it does |
| --- | --- |
| **Match On** | The field on the *other* record type to compare that value against. Usually **Name**, sometimes **Email**. |
| **Create If Missing** | Whether to create the record when nothing matches. |
| **Also Add To** | Optionally add this record to a list on the record it linked to. See [The other direction](#the-other-direction). |

A row does nothing until both **Look Up From** and **Match On** are set. Leaving
**Match On** as *Don't Link* switches the row off.

## Choosing a match field

The same rule as the [match field for upserts](/creating-and-updating): it needs
to identify one record and only one.

**Name** is the obvious choice for organisations, and usually fine. Matching is
**not case-sensitive**, so "st mary's church" finds "St Mary's Church". It is
otherwise exact — punctuation, spacing and spelling all have to agree.

Where you can, offer a **drop-down or autocomplete of real values** in the form
rather than a free-text box. It is the only way to be sure the value matches
something, and it removes the whole class of problem below.

## Create if missing

With this on, a value that matches nothing creates the record. With it off, the
link is skipped and the reason is logged — the submission still goes through and
the record is still written, just without the link.

::: warning Free text plus Create If Missing makes duplicates
"St Mary's", "St Marys", "St. Mary's Church" and "st marys church" are four
different churches as far as matching is concerned, and with creation on you
will get four records.

Turn it on when the form controls the values, or when a new record genuinely is
the right answer for something you have never seen. Leave it off for a free-text
box on a public form, and let the log tell you what people typed.
:::

Creation happens **before** the main record is written, because the ID has to
exist before it can be linked to. If the main write then fails, the record you
created stays. That is worth knowing when you are testing.

## The other direction

Sometimes the link you want lives on the *other* record. A church has a list of
admins; adding someone to it means writing to the Organisation, not the Person.

**Also Add To** does that, once the main record has been written and has an ID.
It lists the fields on the target record type that will accept a record of the
type you are creating.

It **adds** rather than replaces. This matters more than it sounds: a plain
write to a link field in Beacon replaces the whole list, so a naive
implementation would drop every other admin each time someone filled the form
in. The plugin reads the list, adds to it, and writes it back.

::: tip A field that only holds one record
Some link fields hold a single record — **Primary contact** is usually one.
Those can be filled when empty, but not added to. When one is already taken the
plugin leaves it alone and logs it, rather than overwriting whoever is there.

If you want a list that grows — church admins, committee members — the field in
Beacon needs **Allow multiple** turned on.
:::

## What this cannot do: Beacon's Relationships

Beacon has two different ways to connect records, and they look similar in the
interface:

| | Relationships | Point to another record |
| --- | --- | --- |
| Set up under | Settings → Relationships | A field on the record type |
| Has a named type, e.g. Trustee | Yes | No |
| Creates the reciprocal side | Yes | No |
| Start and end dates, notes | Yes | No |
| **Available to this plugin** | **No** | **Yes** |

**Relationships cannot be set through Beacon's API at all**, so this plugin
cannot touch them. That is Beacon's own position, and testing against a live
account confirms it — there is no endpoint.

If you need a form to create a connection, it has to be a
point-to-another-record field. Add one in Beacon under the record type's fields,
with **Allow multiple** on if it should hold a list, and it will appear in
**Linked Records** after you click **Refresh Integration**.

## When a lookup fails

Nothing is ever lost because of a link. The record is written either way, and
the problem is logged against the submission:

```
[ERROR] Beacon: Could not link "c_home_church": no organization where
name is "St Marys". No matching record, and "Create if missing" is off. The
record was still written, without this link.
```

That log is useful in its own right — it tells you exactly what people are
typing, which is the fastest way to find out whether your form needs a drop-down
instead of a text box.

## Speed

**Create If Missing** costs one request. It uses the same upsert Beacon provides
for avoiding duplicates, which matches or creates in a single call.

**Without** it, the plugin has to page through the record type and compare as it
goes, because Beacon has no search endpoint. That is one request per 200 records,
so on a record type holding several thousand a failed lookup can take a couple of
dozen requests. Fine for form traffic, worth knowing if you are importing.
