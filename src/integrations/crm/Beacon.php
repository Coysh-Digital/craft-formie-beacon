<?php

namespace coyshdigital\formiebeacon\integrations\crm;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use CoyshDigital\Beacon\BeaconClient;
use CoyshDigital\Beacon\Config as BeaconConfig;
use CoyshDigital\Beacon\Exception\ApiException;
use CoyshDigital\Beacon\Http\ErrorParser;
use CoyshDigital\Beacon\Payload\EntityPayload;
use CoyshDigital\Beacon\Resource\Entities;
use CoyshDigital\Beacon\Resource\EntityTypes;
use CoyshDigital\Beacon\Schema\EntityType;
use CoyshDigital\Beacon\Schema\Field as BeaconField;
use CoyshDigital\Beacon\Schema\FieldType;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Throwable;
use verbb\formie\base\Crm;
use verbb\formie\base\Integration;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\helpers\ArrayHelper;
use verbb\formie\models\IntegrationField;
use verbb\formie\models\IntegrationFormSettings;
use verbb\formie\models\Payment;
use verbb\formie\models\Stencil;

/**
 * Sends Formie submissions to Beacon CRM.
 *
 * Everything Beacon-specific — reading the account schema, deciding which
 * fields can be written, shaping values into the JSON Beacon expects, and
 * unpacking its errors — lives in the coyshdigital/beaconcrm-php library, which
 * is shared with other projects. This class is the Formie half: the mapping UI,
 * the settings, and the sending, which stays with Formie so its payload events,
 * proxy settings and per-submission logging keep working.
 */
class Beacon extends Crm
{
    // Constants
    // =========================================================================

    /**
     * The id of the main record, as used in `@main` link references.
     */
    public const MAIN_RECORD = 'main';

    /**
     * Prefix of a mapping value that reads from the submission's payment rather
     * than a form field. Formie does not know these, so they are resolved here.
     */
    private const PAYMENT_TOKEN = '{payment:';

    private const STATUS_WRITTEN = 'written';
    private const STATUS_FAILED = 'failed';
    private const STATUS_SKIPPED = 'skipped';


    // Static Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('formie', 'Beacon');
    }


    // Properties
    // =========================================================================

    public ?string $accountId = null;
    public ?string $apiKey = null;
    public ?string $entityType = null;
    public ?array $fieldMapping = null;
    public bool $useUpsert = false;
    public ?string $primaryFieldKey = null;
    public ?array $fixedValues = null;

    /**
     * Which form value finds the record to link to, per link field.
     * `['c_home_church' => '{field:churchName}']`
     *
     * Kept apart from $linkedRecords because Formie's field-mapping component
     * owns this half: it is the only thing that can offer the form's own fields
     * for selection, and it writes one flat value per row.
     */
    public ?array $linkedRecordValues = null;

    /**
     * How to use that value, per link field.
     * `['c_home_church' => ['matchOn' => 'organization:name', …]]`
     */
    public ?array $linkedRecords = null;

    /**
     * Whether the main record is only written once the submission has a
     * successful payment.
     */
    public bool $requirePayment = false;

    /**
     * Further records written after the main one, in order. Each has the same
     * keys as the main record's settings, plus a stable `id` that later records
     * use to link back to it (`@r8f2k` in a Linked Records "Match On").
     *
     * The main record stays in the properties above so forms saved before this
     * existed keep working untouched.
     *
     * `[['id' => 'r8f2k', 'entityType' => 'payment', 'fieldMapping' => […], 'requirePayment' => '1', …], …]`
     */
    public ?array $additionalRecords = null;

    private ?BeaconClient $_beaconClient = null;


    // Public Methods
    // =========================================================================

    public function getDescription(): string
    {
        return Craft::t('formie', 'Create and update records in your {name} CRM database from your form submissions.', ['name' => static::displayName()]);
    }

    public function getIconUrl(): string
    {
        return Craft::$app->getAssetManager()->getPublishedUrl('@coyshdigital/formiebeacon/assets/icon.svg', true);
    }

    public function getSettingsHtml(): ?string
    {
        $variables = $this->getSettingsHtmlVariables();

        return Craft::$app->getView()->renderTemplate('formie-beacon-crm/integrations/crm/beacon/_plugin-settings', $variables);
    }

    public function getFormSettingsHtml(Form|Stencil $form): string
    {
        $variables = $this->getFormSettingsHtmlVariables($form);

        return Craft::$app->getView()->renderTemplate('formie-beacon-crm/integrations/crm/beacon/_form-settings', $variables);
    }

    /**
     * Beacon exposes its entire account schema through a single `entity_types`
     * call — every record type, with each field's type, label, drop-down
     * options and cardinality. Nothing about the schema is hard-coded here, so
     * the integration works against any Beacon account, custom record types
     * and custom `c_*` fields included.
     */
    public function fetchFormSettings(): IntegrationFormSettings
    {
        $settings = [];

        try {
            $response = $this->request('GET', EntityTypes::ENDPOINT);

            // The library parses the schema and sorts the record types by
            // label; Beacon returns them in an arbitrary order that puts custom
            // types before Person.
            $entityTypes = EntityType::listFromResponse($response);

            // A record link names the types it points at, so building its
            // mapping row needs the other types' fields as well as this one's.
            // They are all in the response already, so this costs no extra call.
            $byKey = ArrayHelper::index($entityTypes, 'key');

            foreach ($entityTypes as $entityType) {
                $settings['entityTypes'][] = [
                    'id' => $entityType->key,
                    'name' => $entityType->label,
                    'fields' => $this->_getFields($entityType, $byKey),
                ];
            }
        } catch (Throwable $e) {
            Integration::apiError($this, $e);
        }

        return new IntegrationFormSettings($settings);
    }

    /**
     * Writes the main record, then each additional record in the order they
     * were set up, so a later record can link to an earlier one.
     *
     * A record that fails does not stop the others: only the records that link
     * to it are skipped, because they have nothing to link to. The integration
     * still reports failure, so it shows against the submission.
     */
    public function sendPayload(Submission $submission): bool
    {
        $payment = $this->_getPayment($submission);

        $results = [];
        $ok = true;

        foreach ($this->_getRecordConfigs() as $config) {
            try {
                $result = $this->_writeRecord($submission, $config, $results, $payment);
            } catch (Throwable $e) {
                // Logged, not thrown: apiError() would rethrow, and one record's
                // failure must not stop the records that do not depend on it.
                Integration::apiError($this, $e, false);

                $result = $this->_result(self::STATUS_FAILED, $config['entityType']);
            }

            $results[$config['id']] = $result;

            if ($result['status'] === self::STATUS_FAILED) {
                $ok = false;
            }
        }

        return $ok;
    }

    public function fetchConnection(): bool
    {
        try {
            $this->request('GET', EntityTypes::ENDPOINT);
        } catch (Throwable $e) {
            Integration::apiError($this, $e);

            return false;
        }

        return true;
    }


    // Protected Methods
    // =========================================================================

    /**
     * Formie does the sending, so it needs its own client — but the base URI
     * and headers come from the library's Config, which is the single place
     * they are defined. Beacon rejects a request missing the
     * `Beacon-Application` header as though the key itself were invalid.
     */
    protected function defineClient(): Client
    {
        $config = new BeaconConfig(
            (string)App::parseEnv($this->accountId),
            (string)App::parseEnv($this->apiKey),
        );

        return Craft::createGuzzleClient([
            'base_uri' => $config->accountUri(),
            'headers' => $config->headers(),
        ]);
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['accountId', 'apiKey'], 'required'];

        $rules[] = [['entityType'], 'required', 'on' => [Integration::SCENARIO_FORM]];

        $rules[] = [
            ['primaryFieldKey'], 'required', 'when' => function($model) {
                return $model->enabled && $model->useUpsert;
            }, 'on' => [Integration::SCENARIO_FORM],
        ];

        $rules[] = [
            ['fieldMapping'], 'validateFieldMapping', 'params' => $this->_getTypeFields($this->entityType), 'when' => function($model) {
                return $model->enabled;
            }, 'on' => [Integration::SCENARIO_FORM],
        ];

        $rules[] = [
            ['additionalRecords'], 'validateAdditionalRecords', 'when' => function($model) {
                return $model->enabled;
            }, 'on' => [Integration::SCENARIO_FORM],
        ];

        return $rules;
    }

    /**
     * The extra records need the same things the main one does — a record type,
     * and a match field when updating — but their settings are nested, so the
     * errors say which record they belong to.
     */
    public function validateAdditionalRecords(string $attribute): void
    {
        foreach (array_values($this->additionalRecords ?? []) as $index => $record) {
            if (!is_array($record)) {
                continue;
            }

            $label = Craft::t('formie', 'Additional record {n}', ['n' => $index + 1]);

            if (empty($record['entityType'])) {
                $this->addError($attribute, Craft::t('formie', '{label}: choose a record type, or remove it.', ['label' => $label]));

                continue;
            }

            if (filter_var($record['useUpsert'] ?? false, FILTER_VALIDATE_BOOLEAN) && empty($record['primaryFieldKey'])) {
                $this->addError($attribute, Craft::t('formie', '{label}: choose a Match On field, or turn off Update Existing Records.', ['label' => $label]));
            }
        }
    }


    // Private Methods
    // =========================================================================

    /**
     * Writes one record and says what happened.
     *
     * `skipped` is a deliberate outcome and not a failure: an additional record
     * had nothing to send, or waits on a payment that has not succeeded.
     *
     * Nothing in here may throw for a record-level problem. Formie's
     * `Integration::error()` throws when its last argument is true, which would
     * end the whole submission at the first bad record, so problems are logged
     * and reported back as a status instead.
     *
     * @param array<string, array{status: string, id: ?int, type: string}> $results what happened to the records before this one, by record id
     * @return array{status: string, id: ?int, type: string}
     */
    private function _writeRecord(Submission $submission, array $config, array $results, ?Payment $payment): array
    {
        $typeKey = $config['entityType'];

        if ($config['requirePayment'] && $payment?->status !== Payment::STATUS_SUCCESS) {
            Integration::info($this, Craft::t('formie', '{label} (“{type}”) was not written: it is only sent once the payment has succeeded.', [
                'label' => $config['label'],
                'type' => $typeKey,
            ]));

            return $this->_result(self::STATUS_SKIPPED, $typeKey);
        }

        // Shaping the payload depends on knowing each field's Beacon type,
        // so bail out rather than send unshaped values that Beacon rejects.
        $fields = $this->_getTypeFields($typeKey, true);

        if (!$fields) {
            Integration::error($this, Craft::t('formie', 'Unable to resolve the field schema for “{type}”. Refresh the integration and try again.', [
                'type' => $typeKey,
            ]));

            return $this->_result(self::STATUS_FAILED, $typeKey);
        }

        // Linked records win over fixed and mapped values: a row there says
        // explicitly how to find the record, which is more specific than a raw
        // ID mapped into the same field.
        $links = $this->_resolveLinkedRecords($submission, $config, $results);

        if ($links['blocked']) {
            return $this->_skipForMissingLink($config, $links['blocked']);
        }

        // Fixed values are merged in first so a mapped form field always
        // wins if the same Beacon field has both. They go through the same
        // shaping as mapped values, so a fixed currency or drop-down value
        // still ends up in the right JSON shape.
        $values = array_merge(
            $this->_getFixedValues($config),
            $this->_getMappedValues($submission, $config, $fields, $payment),
            array_map(static fn(array $link): int => $link['id'], $links['resolved'])
        );

        $entity = $this->_buildPayload($values, $fields);

        if ($entity->isEmpty()) {
            Integration::error($this, Craft::t('formie', 'No mapped values to send to {name} for {label} (“{type}”).', [
                'name' => static::displayName(),
                'label' => $config['label'],
                'type' => $typeKey,
            ]));

            // The main record always has something to say, so an empty one is a
            // mistake. An additional record can be legitimately empty — an
            // optional attendee nobody filled in — and is simply left out.
            return $this->_result($config['id'] === self::MAIN_RECORD ? self::STATUS_FAILED : self::STATUS_SKIPPED, $typeKey);
        }

        // Beacon matches an existing record on `primary_field_key`, so that
        // key must also carry a value in the entity body itself.
        if ($config['useUpsert'] && $config['primaryFieldKey'] && !$entity->hasField($config['primaryFieldKey'])) {
            Integration::error($this, Craft::t('formie', 'Upsert key “{key}” is not mapped, so no record can be matched. Map it or disable upsert.', [
                'key' => $config['primaryFieldKey'],
            ]));

            return $this->_result(self::STATUS_FAILED, $typeKey);
        }

        $entities = Entities::describe($typeKey);

        // The library describes the request; Formie sends it, so its payload
        // events, proxy settings and submission logging all still apply.
        $request = $config['useUpsert'] && $config['primaryFieldKey']
            ? $entities->upsertRequest($config['primaryFieldKey'], $entity)
            : $entities->createRequest($entity);

        $method = $request->method;
        $endpoint = $request->path;
        $payload = $request->body ?? [];

        try {
            $response = $this->deliverPayload($submission, $endpoint, $payload, $method);
        } catch (Throwable $e) {
            // Beacon puts the useful part of a validation failure in a
            // nested `raw` property that the default handler truncates
            // away, so unpack it before reporting.
            $this->_logApiFailure($e, $method, $endpoint, $payload, $typeKey);

            return $this->_result(self::STATUS_FAILED, $typeKey);
        }

        if ($response === false) {
            return $this->_result(self::STATUS_SKIPPED, $typeKey);
        }

        $recordId = $response['entity']['id'] ?? null;

        if (!$recordId) {
            Integration::error($this, Craft::t('formie', 'Beacon returned no record ID for {method} {endpoint}. Response: {response} Payload: {payload}', [
                'method' => $method,
                'endpoint' => $endpoint,
                'response' => Json::encode($response),
                'payload' => Json::encode($payload),
            ]));

            return $this->_result(self::STATUS_FAILED, $typeKey);
        }

        $this->_logSuccess($response, $method, $typeKey);

        // The reverse direction, which can only happen once this record has
        // an ID: add it to a list on the record it was linked to.
        $this->_linkBack($links['resolved'], (int)$recordId);

        return $this->_result(self::STATUS_WRITTEN, $typeKey, (int)$recordId);
    }

    /**
     * @return array{status: string, id: ?int, type: string}
     */
    private function _result(string $status, string $type, ?int $id = null): array
    {
        return ['status' => $status, 'id' => $id, 'type' => $type];
    }

    /**
     * A record that links to an earlier record cannot be written if that one
     * never was — there is no ID to link to, and writing it without the link
     * would leave an orphan that looks complete.
     *
     * What the earlier record did decides how this counts. If it failed, this
     * is a failure too; if it was skipped on purpose, so is this.
     *
     * @param array<string, array{ref: string, status: string}> $blocked
     * @return array{status: string, id: ?int, type: string}
     */
    private function _skipForMissingLink(array $config, array $blocked): array
    {
        $failed = false;

        foreach ($blocked as $fieldKey => $block) {
            $failed = $failed || $block['status'] !== self::STATUS_SKIPPED;

            Integration::error($this, Craft::t('formie', '{label} (“{type}”) was not written: “{field}” links to “{ref}”, which was {status}.', [
                'label' => $config['label'],
                'type' => $config['entityType'],
                'field' => $fieldKey,
                'ref' => $block['ref'],
                'status' => match ($block['status']) {
                    self::STATUS_FAILED => Craft::t('formie', 'not written because of an error'),
                    self::STATUS_SKIPPED => Craft::t('formie', 'not written'),
                    default => Craft::t('formie', 'not found. A record can only link to one set up before it'),
                },
            ]));
        }

        return $this->_result($failed ? self::STATUS_FAILED : self::STATUS_SKIPPED, $config['entityType']);
    }

    /**
     * Every record to write, in order: the main record first, then each
     * additional one. Each is normalised to the same shape, so the rest of the
     * class works on one record at a time without caring where it came from.
     *
     * @return list<array{id: string, label: string, entityType: string, fieldMapping: array, useUpsert: bool, primaryFieldKey: string, fixedValues: array, linkedRecordValues: array, linkedRecords: array, requirePayment: bool}>
     */
    private function _getRecordConfigs(): array
    {
        $configs = [
            $this->_normaliseRecord([
                'entityType' => $this->entityType,
                'fieldMapping' => $this->fieldMapping,
                'useUpsert' => $this->useUpsert,
                'primaryFieldKey' => $this->primaryFieldKey,
                'fixedValues' => $this->fixedValues,
                'linkedRecordValues' => $this->linkedRecordValues,
                'linkedRecords' => $this->linkedRecords,
                'requirePayment' => $this->requirePayment,
            ], self::MAIN_RECORD, Craft::t('formie', 'Main record')),
        ];

        foreach (array_values($this->additionalRecords ?? []) as $index => $record) {
            // A record added in the settings but never given a type has nothing
            // to write. Saving the form rejects that, so this only guards old
            // or hand-edited settings.
            if (!is_array($record) || empty($record['entityType'])) {
                continue;
            }

            $configs[] = $this->_normaliseRecord(
                $record,
                (string)($record['id'] ?? '') ?: 'record' . ($index + 1),
                Craft::t('formie', 'Additional record {n}', ['n' => $index + 1])
            );
        }

        return $configs;
    }

    private function _normaliseRecord(array $record, string $id, string $label): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'entityType' => (string)($record['entityType'] ?? ''),
            'fieldMapping' => (array)($record['fieldMapping'] ?? []),
            'useUpsert' => filter_var($record['useUpsert'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'primaryFieldKey' => (string)($record['primaryFieldKey'] ?? ''),
            'fixedValues' => (array)($record['fixedValues'] ?? []),
            'linkedRecordValues' => (array)($record['linkedRecordValues'] ?? []),
            'linkedRecords' => (array)($record['linkedRecords'] ?? []),
            'requirePayment' => filter_var($record['requirePayment'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * The payment this submission made, if any: the newest successful one, or
     * failing that the newest of any status, so a status can still be mapped.
     */
    private function _getPayment(Submission $submission): ?Payment
    {
        $payments = $submission->getPayments() ?: [];

        usort($payments, static fn(Payment $a, Payment $b): int => $b->id <=> $a->id);

        foreach ($payments as $payment) {
            if ($payment->status === Payment::STATUS_SUCCESS) {
                return $payment;
            }
        }

        return $payments[0] ?? null;
    }

    /**
     * The payment values a mapping row can pick, by the token it is stored as.
     *
     * @return array<string, mixed>
     */
    private function _getPaymentValues(Payment $payment): array
    {
        return [
            self::PAYMENT_TOKEN . 'amount}' => $payment->amount,
            self::PAYMENT_TOKEN . 'currency}' => $payment->currency,
            self::PAYMENT_TOKEN . 'reference}' => $payment->reference,
            self::PAYMENT_TOKEN . 'status}' => $payment->status,
            self::PAYMENT_TOKEN . 'date}' => $payment->dateCreated?->format('c'),
            self::PAYMENT_TOKEN . 'provider}' => $payment->integrationId ? $payment->getIntegration()?->displayName() : null,
        ];
    }

    /**
     * The choices added to a mapping row's "Form Field" dropdown, beside the
     * form's own fields.
     */
    private function _getPaymentOptions(): array
    {
        return [
            'label' => Craft::t('formie', 'Payment'),
            // Marks these as something to map from, not a drop-down's own
            // choices, which the Fixed Values table also reads from `options`.
            'payment' => true,
            'options' => [
                ['label' => Craft::t('formie', 'Amount'), 'value' => self::PAYMENT_TOKEN . 'amount}'],
                ['label' => Craft::t('formie', 'Currency'), 'value' => self::PAYMENT_TOKEN . 'currency}'],
                ['label' => Craft::t('formie', 'Reference'), 'value' => self::PAYMENT_TOKEN . 'reference}'],
                ['label' => Craft::t('formie', 'Status'), 'value' => self::PAYMENT_TOKEN . 'status}'],
                ['label' => Craft::t('formie', 'Date'), 'value' => self::PAYMENT_TOKEN . 'date}'],
                ['label' => Craft::t('formie', 'Provider'), 'value' => self::PAYMENT_TOKEN . 'provider}'],
            ],
        ];
    }

    /**
     * The values from the Field Mapping table.
     *
     * Formie resolves `{field:…}` and `{submission:…}`, but a `{payment:…}`
     * value means nothing to it, so those rows are taken out and read from the
     * payment here. They are cast the same way, so a payment amount mapped to a
     * currency field is shaped like any other currency value.
     */
    private function _getMappedValues(Submission $submission, array $config, array $fields, ?Payment $payment): array
    {
        $mapping = $config['fieldMapping'];

        $paymentMapping = array_filter(
            $mapping,
            static fn($value): bool => is_string($value) && str_starts_with($value, self::PAYMENT_TOKEN)
        );

        $values = $this->getFieldMappingValues($submission, array_diff_key($mapping, $paymentMapping), $fields);

        if (!$paymentMapping || !$payment) {
            return $values;
        }

        $paymentValues = $this->_getPaymentValues($payment);

        foreach ($paymentMapping as $handle => $token) {
            $value = $paymentValues[$token] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $field = ArrayHelper::firstWhere($fields, 'handle', $handle) ?? new IntegrationField();

            $values[$handle] = static::convertValueForIntegration($value, $field);
        }

        return $values;
    }

    /**
     * Values entered directly in the form settings, sent on every submission.
     * Blank entries are dropped so an empty box is simply not sent.
     */
    private function _getFixedValues(array $config): array
    {
        $values = [];

        foreach ($config['fixedValues'] as $handle => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $values[$handle] = $value;
        }

        return $values;
    }

    /**
     * Turns the values behind the Linked Records table into Beacon record IDs.
     *
     * A link field stores an integer record ID, which a form never has. Each
     * configured row either names a field on the target record type to match the
     * submitted value against — a church name, an email address — and whether
     * to create the record when nothing matches, or points at a record written
     * earlier in this same submission (`@main`, `@r8f2k`), whose ID is already
     * known.
     *
     * A lookup that cannot be resolved is logged and skipped rather than failing
     * the submission. Losing the link to a church is bad; losing the whole
     * enquiry because the church name was misspelt is worse. A reference to a
     * record that was not written is different: it is reported back as
     * `blocked`, so the caller can skip this record too.
     *
     * @param array<string, array{status: string, id: ?int, type: string}> $results what happened to the records before this one, by record id
     * @return array{resolved: array<string, array{id: int, backType: ?string, backField: ?string, targetType: string}>, blocked: array<string, array{ref: string, status: string}>}
     */
    private function _resolveLinkedRecords(Submission $submission, array $config, array $results): array
    {
        $mapping = array_filter($config['linkedRecordValues'], static fn($value): bool => $value !== '' && $value !== null);

        // A lookup does nothing without both halves: a value to look up, and a
        // field to look it up against. A reference to an earlier record has
        // nothing to look up, so it needs only the one.
        $rows = array_filter(
            $config['linkedRecords'],
            static fn($row, $key): bool => is_array($row) && ($row['matchOn'] ?? '') !== '' && (str_starts_with((string)$row['matchOn'], '@') || isset($mapping[$key])),
            ARRAY_FILTER_USE_BOTH
        );

        if (!$rows) {
            return ['resolved' => [], 'blocked' => []];
        }

        $resolved = [];
        $blocked = [];

        foreach ($rows as $fieldKey => $row) {
            if (!str_starts_with((string)$row['matchOn'], '@')) {
                continue;
            }

            $ref = substr((string)$row['matchOn'], 1);
            $result = $results[$ref] ?? null;

            if (($result['status'] ?? null) !== self::STATUS_WRITTEN) {
                $blocked[$fieldKey] = ['ref' => $ref, 'status' => $result['status'] ?? 'missing'];

                continue;
            }

            [$backType, $backField] = $this->_splitTarget((string)($row['linkBack'] ?? ''));

            $resolved[$fieldKey] = [
                'id' => $result['id'],
                'targetType' => $result['type'],
                'backType' => $backType,
                'backField' => $backField,
            ];
        }

        $rows = array_filter($rows, static fn($row): bool => !str_starts_with((string)$row['matchOn'], '@'));

        if (!$rows) {
            return ['resolved' => $resolved, 'blocked' => $blocked];
        }

        // Reuse Formie's own token resolution, so `{field:…}` and
        // `{submission:…}` behave exactly as they do in the mapping table.
        //
        // The field definitions are rebuilt as plain strings rather than reused
        // from $fields. A link field's own type is array — that is what Beacon
        // stores — but what is being read here is the church name that finds
        // the record, so casting it to the link's type would wrap the name in
        // an array before anything could look it up.
        $lookupFields = array_map(
            static fn(string $handle): IntegrationField => new IntegrationField([
                'handle' => $handle,
                'type' => IntegrationField::TYPE_STRING,
            ]),
            array_keys($rows)
        );

        $values = $this->getFieldMappingValues(
            $submission,
            array_intersect_key($mapping, $rows),
            $lookupFields
        );

        foreach ($rows as $fieldKey => $row) {
            $value = $values[$fieldKey] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            [$targetType, $matchField] = $this->_splitTarget((string)$row['matchOn']);

            if (!$targetType || !$matchField) {
                continue;
            }

            try {
                $id = $this->_lookUpRecord($targetType, $matchField, $value, !empty($row['create']));
            } catch (Throwable $e) {
                $this->_logLinkFailure($fieldKey, $targetType, $matchField, $value, $e->getMessage());

                continue;
            }

            if (!$id) {
                $this->_logLinkFailure($fieldKey, $targetType, $matchField, $value, Craft::t('formie', 'No matching record, and “Create if missing” is off.'));

                continue;
            }

            [$backType, $backField] = $this->_splitTarget((string)($row['linkBack'] ?? ''));

            $resolved[$fieldKey] = [
                'id' => $id,
                'targetType' => $targetType,
                'backType' => $backType,
                'backField' => $backField,
            ];
        }

        return ['resolved' => $resolved, 'blocked' => $blocked];
    }

    /**
     * Finds the record to link to, creating it when asked.
     *
     * `resolveId()` is an upsert: one request, and it creates when nothing
     * matches. `findBy()` never creates, but has to page the whole record type
     * because Beacon has no search endpoint — so it is the slower, safer
     * option, and the one to pick when a typo must not spawn a record.
     */
    private function _lookUpRecord(string $typeKey, string $matchField, mixed $value, bool $create): ?int
    {
        $records = $this->_beaconClient()->entitiesWithSchema($typeKey);

        if ($create) {
            return $records->resolveId($matchField, $records->payload()->set($matchField, $value));
        }

        $record = $records->findBy($matchField, $value);
        $id = $record['id'] ?? null;

        return is_numeric($id) ? (int)$id : null;
    }

    /**
     * Adds the record just written to a list on the record it was linked to.
     *
     * This is the half a payload cannot express: the field lives on the *other*
     * record, so it can only be set once this one has an ID. It goes through
     * the library's `link()`, which reads the list and sends it back with the
     * new ID appended — a plain write would replace the list and drop every
     * other entry.
     *
     * @param array<string, array{id: int, backType: ?string, backField: ?string, targetType: string}> $links
     */
    private function _linkBack(array $links, int $recordId): void
    {
        if (!$recordId) {
            return;
        }

        foreach ($links as $fieldKey => $link) {
            if (!$link['backType'] || !$link['backField']) {
                continue;
            }

            try {
                $this->_beaconClient()
                    ->entities($link['backType'])
                    ->link($link['id'], $link['backField'], $recordId);
            } catch (Throwable $e) {
                $detail = $e instanceof ApiException ? $e->getSummary() : $e->getMessage();

                // Beacon phrases a full single-value field as "must contain
                // less than 1 items", which reads like a bug rather than a
                // field that is simply already taken.
                if (str_contains($detail, 'must contain less than')) {
                    $detail .= ' ' . Craft::t('formie', 'That field holds one record and already has one, so it was left alone rather than overwritten.');
                }

                // Logged, not thrown. The record itself was written, and losing
                // the submission over the link would be the worse outcome.
                Integration::error($this, Craft::t('formie', 'Beacon record #{id} was written, but it could not be added to “{field}” on {type} #{target}. {detail}', [
                    'id' => $recordId,
                    'field' => $link['backField'],
                    'type' => $link['backType'],
                    'target' => $link['id'],
                    'detail' => $detail,
                ]));
            }
        }
    }

    /**
     * Splits a `recordType:fieldKey` option value.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function _splitTarget(string $value): array
    {
        if (!str_contains($value, ':')) {
            return [null, null];
        }

        [$type, $field] = explode(':', $value, 2);

        return [$type !== '' ? $type : null, $field !== '' ? $field : null];
    }

    /**
     * Logged, not thrown. A link that cannot be resolved should cost the link,
     * not the whole submission — a misspelt church name is not a reason to lose
     * an enquiry.
     */
    private function _logLinkFailure(string $fieldKey, string $typeKey, string $matchField, mixed $value, string $detail): void
    {
        Integration::error($this, Craft::t('formie', 'Could not link “{field}”: no {type} where {matchField} is “{value}”. {detail} The record was still written, without this link.', [
            'field' => $fieldKey,
            'type' => $typeKey,
            'matchField' => $matchField,
            'value' => is_scalar($value) ? (string)$value : Json::encode($value),
            'detail' => $detail,
        ]));
    }

    /**
     * A client that can send, for the lookups Formie cannot do.
     *
     * Everything else in this class hands an unsent request to Formie's
     * `deliverPayload()`, so its payload events, proxy settings and per-submission
     * logging keep working. Lookups cannot go that way: finding or creating a
     * record is several requests, or an upsert whose response is needed before
     * the real payload can be built, and neither is a single describable request
     * that Formie could send on our behalf.
     *
     * The main record is still written through Formie. Only the lookups and the
     * link-back go direct.
     */
    private function _beaconClient(): BeaconClient
    {
        return $this->_beaconClient ??= new BeaconClient(new BeaconConfig(
            (string)App::parseEnv($this->accountId),
            (string)App::parseEnv($this->apiKey),
        ));
    }

    /**
     * Reports a failed write with as much detail as Beacon gave us.
     *
     * Beacon returns validation problems as a 500 whose body carries the real
     * cause in `error.raw`, e.g. `Validation error: "emails": 0`. Formie's
     * default handler shows only the outer message, which is always the
     * unhelpful "Oh shoot! An unknown error occurred."
     */
    private function _logApiFailure(Throwable $e, string $method, string $endpoint, array $payload, string $typeKey): void
    {
        $detail = $e->getMessage();

        if ($e instanceof RequestException && $e->getResponse()) {
            $detail = ErrorParser::fromResponse($e->getResponse(), $method, $endpoint)->getSummary();
        } elseif ($e instanceof ApiException) {
            $detail = $e->getSummary();
        }

        Integration::error($this, Craft::t('formie', 'Beacon rejected {method} {endpoint} for record type “{type}”. {detail} Payload: {payload}', [
            'method' => $method,
            'endpoint' => $endpoint,
            'type' => $typeKey,
            'detail' => $detail,
            'payload' => Json::encode($payload),
        ]));
    }

    /**
     * Records the Beacon ID of every record written, so submissions can be
     * reconciled against the CRM later.
     */
    private function _logSuccess(array $response, string $method, string $typeKey): void
    {
        $entity = $response['entity'] ?? [];
        $id = $entity['id'] ?? null;

        // An upsert does not say whether it matched or inserted, but an
        // untouched record still has its creation timestamp as its last
        // modification, which is a reliable enough signal for a log line.
        $action = 'created';

        if ($method === 'PUT') {
            $created = $entity['created_at'] ?? null;
            $updated = $entity['updated_at'] ?? null;
            $action = ($created && $updated && $created !== $updated) ? 'updated' : 'created';
        }

        Integration::info($this, Craft::t('formie', 'Beacon record {action}: {type} #{id}', [
            'action' => $action,
            'type' => $typeKey,
            'id' => (string)$id,
        ]));
    }

    /**
     * Builds the mapping rows for one record type.
     *
     * The library decides what can be written — read-only, smart and rollup
     * fields are computed by Beacon and rejected on write, and file and user
     * fields need more than a single mapped value can carry.
     */
    private function _getFields(EntityType $entityType, array $typesByKey = []): array
    {
        $integrationFields = [];

        foreach ($entityType->mappableFields() as $field) {
            // Person names and addresses are structured objects, so expose one
            // mapping row per part and reassemble them when sending. A single
            // row would be worse than useless: Formie casts a mapped value to
            // its row's type, and an address cast to a string is the literal
            // "Array".
            $parts = $field->parts();

            if ($parts !== []) {
                foreach ($parts as $part) {
                    $integrationFields[] = new IntegrationField([
                        'handle' => $field->key . BeaconField::PART_SEPARATOR . $part,
                        'name' => $field->label . ' (' . ucfirst(str_replace('_', ' ', $part)) . ')',
                        'type' => IntegrationField::TYPE_STRING,
                        'sourceType' => $field->rawType,
                    ]);
                }

                continue;
            }

            $options = $this->_getFieldOptions($field);
            $linkData = $this->_getLinkData($field, $entityType, $typesByKey);

            // A drop-down's own choices matter more than a payment value could,
            // and a link takes a record ID, so only plain fields get the payment
            // values as something to map.
            if (!$options && !$linkData) {
                $options = $this->_getPaymentOptions();
            }

            $integrationFields[] = new IntegrationField([
                'handle' => $field->key,
                'name' => $field->label,
                'type' => $this->_convertFieldType($field),
                'sourceType' => $field->rawType,
                'options' => $options,
                'data' => $linkData,
            ]);
        }

        return $integrationFields;
    }

    /**
     * The choices a record-link field offers in the Linked Records table.
     *
     * A link stores an integer record ID, but a form collects a name or an
     * email address. So each link field needs to know which field on the record
     * it points at can be matched against — and, for the reverse direction,
     * which field over there points back at this record type.
     *
     * Both option lists are flat, with the target record type encoded into the
     * value as `type:field`. A link may point at more than one record type, and
     * one flat list avoids a second select whose options depend on the first.
     */
    private function _getLinkData(BeaconField $field, EntityType $entityType, array $typesByKey): array
    {
        $targetKeys = $field->linksTo();

        if (!$targetKeys) {
            return [];
        }

        $match = [];
        $back = [];

        // Only worth naming the record type when there is a choice of them.
        $prefixed = count($targetKeys) > 1;

        foreach ($targetKeys as $targetKey) {
            $target = $typesByKey[$targetKey] ?? null;

            if (!$target instanceof EntityType) {
                continue;
            }

            $prefix = $prefixed ? $target->label . ' → ' : '';

            foreach ($target->mappableFields() as $targetField) {
                // A structured field cannot be matched on as a single value,
                // and matching one link against another makes no sense.
                if ($targetField->parts() || $targetField->isReference()) {
                    continue;
                }

                $match[] = [
                    'value' => $targetKey . ':' . $targetField->key,
                    'label' => $prefix . $targetField->label,
                ];
            }

            foreach ($target->fields() as $targetField) {
                if (!$targetField->isReference() || !$targetField->isWritable()) {
                    continue;
                }

                // Only fields over there that will accept a record of this type.
                if (!in_array($entityType->key, $targetField->linksTo(), true)) {
                    continue;
                }

                $back[] = [
                    'value' => $targetKey . ':' . $targetField->key,
                    'label' => $prefix . $targetField->label,
                ];
            }
        }

        return ['link' => ['match' => $match, 'back' => $back, 'targets' => $targetKeys]];
    }

    /**
     * Maps a Beacon field type onto the Formie type that drives the mapping UI.
     */
    private function _convertFieldType(BeaconField $field): string
    {
        return match ($field->type) {
            FieldType::Number, FieldType::Rating => IntegrationField::TYPE_NUMBER,
            FieldType::Currency, FieldType::Percent => IntegrationField::TYPE_FLOAT,
            FieldType::Boolean => IntegrationField::TYPE_BOOLEAN,
            FieldType::Date => $field->includesTime() ? IntegrationField::TYPE_DATETIME : IntegrationField::TYPE_DATE,
            FieldType::Phone => IntegrationField::TYPE_PHONE,
            FieldType::Reference => IntegrationField::TYPE_ARRAY,
            FieldType::Select => $field->allowsMultiple() ? IntegrationField::TYPE_ARRAY : IntegrationField::TYPE_STRING,
            default => IntegrationField::TYPE_STRING,
        };
    }

    /**
     * Surfaces a drop-down's configured values so they can be picked in the
     * mapping UI rather than typed by hand. Beacon rejects any value that is
     * not configured for the field.
     */
    private function _getFieldOptions(BeaconField $field): array
    {
        $options = $field->options();

        if (!$options) {
            return [];
        }

        return [
            'label' => $field->label,
            'options' => array_map(fn($option) => [
                'label' => $option,
                'value' => $option,
            ], $options),
        ];
    }

    /**
     * The mapping rows for a record type, used for both validation and payload
     * shaping.
     */
    private function _getTypeFields(?string $typeKey, bool $allowFetch = false): array
    {
        if (!$typeKey) {
            return [];
        }

        $entityTypes = $this->getFormSettingValue('entityTypes');

        // `getFormSettings()` only reads Formie's stored settings, which stay
        // empty until the integration has been refreshed. Rather than shape a
        // payload against an unknown schema, fetch it once here. This throws if
        // the integration has never connected, so failure is handled by the
        // caller reporting that the schema could not be resolved.
        if (!$entityTypes && $allowFetch) {
            try {
                $settings = $this->getFormSettings(false);

                if ($settings instanceof IntegrationFormSettings) {
                    $entityTypes = $settings->getSettingsByKey('entityTypes');
                }
            } catch (Throwable $e) {
                // Log without re-throwing, so the caller can report the more
                // useful "schema could not be resolved" message instead.
                Integration::apiError($this, $e, false);

                return [];
            }
        }

        if (!$entityTypes) {
            return [];
        }

        $entityType = ArrayHelper::firstWhere($entityTypes, 'id', $typeKey);

        return $entityType['fields'] ?? [];
    }

    /**
     * Turns flat mapped values into an entity payload.
     *
     * The library shapes each value for its Beacon field type — names become
     * objects, emails, phones and addresses become arrays of objects, drop-downs
     * and record links become arrays, currency becomes an object, and numeric
     * fields become JSON numbers. The Beacon type of each field comes from the
     * `sourceType` stored on the mapping row, so no schema call is needed to
     * send.
     */
    private function _buildPayload(array $values, array $fieldDefs): EntityPayload
    {
        $fields = ArrayHelper::index($fieldDefs, 'handle');

        // Structured fields are assembled by the payload builder from their
        // parts, and the rows are keyed by part handle — `address:city` — so
        // the resolver is asked about those rather than the whole field.
        return EntityPayload::resolvedBy(
            static fn(string $key): ?FieldType => FieldType::tryFromName($fields[$key]->sourceType ?? null),
        )->setMany($values);
    }
}
