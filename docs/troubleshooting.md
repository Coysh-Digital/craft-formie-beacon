# Troubleshooting

## Where to look first

Formie records what happened for every submission. Open **Formie → Submissions**,
pick the submission, and open its **Integrations** tab. You will see the payload
that was sent and whatever Beacon said back.

Everything is also written to `storage/logs/formie.log`.

### What gets logged

Every successful write is logged with the Beacon record ID, so you can reconcile
a submission against the record it produced:

```
[INFO] Beacon: Beacon record created: person #516319
[INFO] Beacon: Beacon record updated: person #516319
```

Failures are logged with the request, Beacon's error code, and the specific
validation problem:

```
[ERROR] Beacon: Beacon rejected PUT entity/person/upsert for record type
"person". HTTP 500 | server_error | Oh shoot! An unknown error occurred. |
Detail: Validation error: "gender": 0 Invalid option. Allowed options: Male,
Female, Non-binary, Prefer not to say Payload: {…}
```

The **Detail** part is the one worth reading. Beacon returns validation failures
as a `500` whose top-level message is always the unhelpful "Oh shoot! An unknown
error occurred", with the real cause buried further down. The plugin digs that
out and puts it in the log, along with the exact payload that was sent, so you
can usually see the problem without reproducing it.

::: tip Log entries are prefixed with the integration name
If you have more than one Beacon integration, the name you gave it appears at
the start of each line, so you can tell them apart.
:::

## The connection check fails

**`invalid_api_key`** means Beacon rejected the key. It has been revoked, it was
mistyped, or the environment variable is not resolving. Check that the settings
field contains `$BEACON_API_KEY` and that the variable is set in the `.env` for
*that* environment. A key that works locally will fail in production if it was
never added there.

**A 404** usually means the account ID is wrong. It should be just the number,
such as `12345`, not the full URL.

## No record types in the list

If **Record Type** is empty, the plugin could not read your schema. Check the
connection first, then click **Refresh Integration**.

If the connection is fine but the list is still empty, your API key may not have
access. API access depends on your Beacon plan.

## A field is missing from the mapping list

Most likely it is a file upload, an address, or a field Beacon calculates for
itself. These are excluded on purpose, and
[How field types are handled](/field-types) explains why.

Otherwise, click **Refresh Integration**. The schema is stored after it is first
read, so fields added in Beacon since then will not appear until you refresh.

## Submissions are not reaching Beacon

**Check the opt-in field.** If you set one and the person left it empty, the
submission is skipped by design and no record is created.

**Check the integration is enabled** both globally under **Formie → Settings →
CRM** and on the individual form.

**"Unable to resolve the field schema"** means the plugin could not read the
field definitions for the record type. Click **Refresh Integration** on the
form. This can also happen if the record type was deleted or renamed in Beacon
after the form was set up.

**"Upsert key … is not mapped"** means **Update Existing Records** is on but the
**Match On** field is not mapped. Beacon needs that value in the submission to
find the record. Either map it or turn upsert off. See
[Creating and updating records](/creating-and-updating).

## A value is not saving

**Drop-downs** reject anything not configured on the field, and matching is
exact, including capitalisation. If you mapped a drop-down to a form field, make
sure the form's values match the Beacon options character for character. Picking
a fixed value in the mapping screen avoids this entirely.

**Record links** need the numeric ID of an existing Beacon record. A name will
not work.

**Currency** amounts are in major units, so `25.5` is £25.50.

## Duplicate records

Turn on **Update Existing Records** and pick a match field. See
[Creating and updating records](/creating-and-updating).

If duplicates already exist, deduplicate in Beacon first. Upserting into a
database that already has duplicates updates whichever record Beacon matches,
which tends to hide the problem rather than fix it.

## Rate limits

Beacon allows 300 requests per minute, and 60 per minute for bulk operations.
Going over returns a `429`.

Normal form traffic will not get near this. If you are seeing `429`, something
is submitting in bulk, such as an import or a load test.

## Unexpected emails or tasks after a submission

That is a Beacon workflow, not the plugin. Records created through the API are
treated the same as any other record, so a workflow watching for new people will
fire for form submissions too. Review your active workflows in Beacon.
