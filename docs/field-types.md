# How field types are handled

Beacon expects a different JSON shape for each kind of field, and it is fussy
about them. The plugin converts your mapped values into the right shape so you
do not have to think about it. This page documents what it does, which is
useful when a value is not saving the way you expect.

The conversions themselves live in
[coyshdigital/beaconcrm-php](https://github.com/Coysh-Digital/beaconcrm-php),
the Beacon API library the plugin is built on, so they behave identically
anywhere else you use it.

## Conversions

| Beacon field type | What gets sent |
| --- | --- |
| Short text, long text, URL | The value as-is |
| Person name | An object of name parts, with `full` derived if you only mapped the parts |
| Email | `[{ "email": "…", "is_primary": true }]` |
| Phone | `[{ "number": "…", "is_primary": true }]` |
| Drop-down | An array of values, **even for single-select fields** |
| Record link | An array of integer Beacon record IDs |
| Checkbox | A JSON boolean |
| Number, percent, rating | A JSON number |
| Currency | An object, `{ "value": 25.5 }` |
| Date | An ISO date, such as `2026-07-21` |

## A note on currency

Currency fields are the one type that does not take a plain number. Beacon
wants an object, and amounts are in **major units**: `25.5` means £25.50, not
25.5 pence.

The currency code is left off deliberately, so Beacon applies your account's
default currency. In its response you will see the value echoed back with the
currency filled in:

```json
{ "currency": "GBP", "value": 25.5, "base_value": 25.5 }
```

::: warning Why this matters
If a bare number is sent to a currency field, Beacon accepts the request with a
success response and then stores nothing. The amount vanishes with no error
anywhere. The plugin always sends the object form so this cannot happen, but it
is worth knowing if you are writing to the Beacon API from anywhere else.
:::

## Fields you will not see in the mapping list

Some fields are deliberately left out, because mapping them could not work.

**File uploads.** Beacon requires a separate signed-upload handshake that
cannot happen inside the record payload. Mapping a Formie file field to one
would always fail, so upload fields are hidden. This affects **Attachments**
and any custom file field.

**Addresses and locations.** Beacon expects a structured address object that a
single mapped form field cannot express.

**Anything Beacon calculates.** Smart fields, rollup fields and auto-increment
fields are all computed by Beacon and rejected on write. This covers a lot of
useful-looking fields, such as totals, counts and percentages, which are
derived from other records rather than set directly.

If a field you expected is missing, it is almost certainly one of these. You
can confirm by checking the field in Beacon: if it shows a formula, a rollup,
or is greyed out when you edit a record by hand, it is read-only.

## Empty values

Empty values are skipped rather than sent as empty. A blank optional field on
your form will not overwrite data already held in Beacon.

Note that `0` and `false` are real values, not empty ones, and are sent.
