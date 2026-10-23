# Multiple records per submission

One submission often means more than one thing in Beacon. Someone registering
for an event is a **Person**, an **Event attendee** and, if they paid, a
**Payment**. Writing those as three separate integrations does not work well:
they run independently and in no fixed order, so the attendee cannot find out
which person it belongs to.

**Additional Records** writes them together, in order, from a single
integration. Each one can link to the records written before it.

## Setting it up

On the form's **Integrations → Beacon** tab, the settings you already had are
now labelled **Main Record**. Nothing about them changes, and forms set up
before this existed carry on exactly as they were.

Below them, **Additional Records** is a list. Choose **Add Record** and, for
each one:

1. Pick a **Record Type**.
2. Set it up exactly as you would the main record: field mapping, fixed values
   and linked records. Optionally turn on **Update Existing Records**.
3. Use **Move Up** and **Move Down** to put them in the order they should be
   written, and **Remove** to take one out.

Records are written top to bottom: the main record first, then each additional
record in turn.

## Linking one record to another

This is what makes it worth doing. In an additional record's **Linked Records**
table, a link field's **Match On** list has an extra group at the bottom,
**Record from this submission**. It offers the main record and every additional
record above this one, as long as the field accepts that type of record.

Choose one and the new record's ID is filled in automatically. There is nothing
to look up, so **Look Up From** can stay empty for that row.

A worked example, for an event registration:

| Record | Type | Set up as |
| --- | --- | --- |
| Main | Person | Name and email mapped. **Update Existing Records** on, matching on email, so a returning person is updated rather than duplicated. |
| Additional 1 | Event attendee | **Person** link set to *Main record*. **Event** set as a fixed value. |
| Additional 2 | Payment | **Person** link set to *Main record*. **Amount** mapped to the payment amount. **Only If Payment Succeeded** on. |

A record can only link to one **above** it. If you reorder the list so a record
ends up above the one it links to, it is skipped and the log says why.

## Payment values

Wherever you can map a form field, plain fields also offer a **Payment** group:

| Value | What it is |
| --- | --- |
| Amount | The amount paid, in major units (`25.50`, not `2550`). |
| Currency | The currency code, such as `GBP`. |
| Reference | The payment provider's reference, such as a Stripe payment intent ID. |
| Status | `success`, `failed` and so on. |
| Date | When the payment was made. |
| Provider | The payment integration used, such as Stripe. |

They come from the Payment field on the submission, and work in the main record
and in any additional record. They are shaped like any other value, so an amount
mapped to a currency field arrives as a currency.

If the submission has more than one payment, the newest successful one is used.
Failing that, the newest of any status, so you can still record a failed
attempt through **Status**.

Drop-down fields and link fields do not offer payment values, because they take
a configured choice or a record rather than free text.

### Only if the payment succeeded

Each record has an **Only If Payment Succeeded** switch. With it on, the record
is written only when the submission has a successful payment. Otherwise it is
skipped and the log says so. A skip is not counted as a failure.

Formie only sends a submission to its integrations once payment has gone
through, so this matters mostly when the payment is **optional**: a Payment
field that only shows for some answers, or a form where paying is one choice
among several. There, the person and attendee are always written, and the
payment record only when there is a payment.

## When something fails

Each record is written on its own, so one failure does not take the rest down
with it. What happens depends on how the records relate:

- A record that **links to** the failed one is skipped, because there is no
  record to link to. Writing it without the link would leave something that
  looks complete and is not.
- A record that does **not** link to it is still written.
- The integration is reported as failed, so it shows against the submission and
  can be retried.

The log names each record, so you can see which succeeded and which did not:

```
[ERROR] Beacon: Additional record 1 ("activity") was not written: "related_entity_ids"
links to "main", which was not written because of an error.
```

A record skipped deliberately, such as a payment record with no payment, is not
a failure, and neither are the records that link to it.

An **additional** record with nothing to send, because every mapped field was
empty, is left out quietly. For the **main** record that is treated as a
mistake, as it always has been.

::: warning Resending a submission writes every record again
Retrying the integration runs the whole thing again, and anything with
**Update Existing Records** off is created again. Turn it on for the main
record at least, matching on something unique such as email, so a retry does not
leave a duplicate person.

Records already written are not tracked between attempts.
:::

## Limits

- Records are written one after another, so a form with several records takes
  proportionally longer. Formie sends integrations from the queue, so this does
  not slow the person submitting the form.
- Only the **Linked Records** table reaches records written earlier. A raw ID
  mapped into a link field still works but cannot refer to another record in the
  same submission.
- Beacon's [Relationships](/linking-records#what-this-cannot-do-beacons-relationships)
  cannot be set through its API, here or anywhere else.
