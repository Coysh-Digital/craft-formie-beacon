<?php

namespace coyshdigital\formiebeacon\integrations\crm;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
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
            foreach (EntityType::listFromResponse($response) as $entityType) {
                $settings['entityTypes'][] = [
                    'id' => $entityType->key,
                    'name' => $entityType->label,
                    'fields' => $this->_getFields($entityType),
                ];
            }
        } catch (Throwable $e) {
            Integration::apiError($this, $e);
        }

        return new IntegrationFormSettings($settings);
    }

    public function sendPayload(Submission $submission): bool
    {
        try {
            // Shaping the payload depends on knowing each field's Beacon type,
            // so bail out rather than send unshaped values that Beacon rejects.
            $fields = $this->_getSelectedTypeFields(true);

            if (!$fields) {
                Integration::error($this, Craft::t('formie', 'Unable to resolve the field schema for “{type}”. Refresh the integration and try again.', [
                    'type' => $this->entityType,
                ]), true);

                return false;
            }

            // Fixed values are merged in first so a mapped form field always
            // wins if the same Beacon field has both. They go through the same
            // shaping as mapped values, so a fixed currency or drop-down value
            // still ends up in the right JSON shape.
            $values = array_merge(
                $this->_getFixedValues(),
                $this->getFieldMappingValues($submission, $this->fieldMapping, $fields)
            );

            $entity = $this->_buildPayload($values, $fields);

            if ($entity->isEmpty()) {
                Integration::error($this, Craft::t('formie', 'No mapped values to send to {name}.', [
                    'name' => static::displayName(),
                ]), true);

                return true;
            }

            // Beacon matches an existing record on `primary_field_key`, so that
            // key must also carry a value in the entity body itself.
            if ($this->useUpsert && $this->primaryFieldKey && !$entity->hasField($this->primaryFieldKey)) {
                Integration::error($this, Craft::t('formie', 'Upsert key “{key}” is not mapped, so no record can be matched. Map it or disable upsert.', [
                    'key' => $this->primaryFieldKey,
                ]), true);

                return false;
            }

            $entities = Entities::describe((string)$this->entityType);

            // The library describes the request; Formie sends it, so its payload
            // events, proxy settings and submission logging all still apply.
            $request = $this->useUpsert && $this->primaryFieldKey
                ? $entities->upsertRequest($this->primaryFieldKey, $entity)
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
                $this->_logApiFailure($e, $method, $endpoint, $payload);

                return false;
            }

            if ($response === false) {
                return true;
            }

            $recordId = $response['entity']['id'] ?? null;

            if (!$recordId) {
                Integration::error($this, Craft::t('formie', 'Beacon returned no record ID for {method} {endpoint}. Response: {response} Payload: {payload}', [
                    'method' => $method,
                    'endpoint' => $endpoint,
                    'response' => Json::encode($response),
                    'payload' => Json::encode($payload),
                ]), true);

                return false;
            }

            $this->_logSuccess($response, $method);
        } catch (Throwable $e) {
            Integration::apiError($this, $e);

            return false;
        }

        return true;
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
            ['fieldMapping'], 'validateFieldMapping', 'params' => $this->_getSelectedTypeFields(), 'when' => function($model) {
                return $model->enabled;
            }, 'on' => [Integration::SCENARIO_FORM],
        ];

        return $rules;
    }


    // Private Methods
    // =========================================================================

    /**
     * Values entered directly in the form settings, sent on every submission.
     * Blank entries are dropped so an empty box is simply not sent.
     */
    private function _getFixedValues(): array
    {
        $values = [];

        foreach ($this->fixedValues ?? [] as $handle => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $values[$handle] = $value;
        }

        return $values;
    }

    /**
     * Reports a failed write with as much detail as Beacon gave us.
     *
     * Beacon returns validation problems as a 500 whose body carries the real
     * cause in `error.raw`, e.g. `Validation error: "emails": 0`. Formie's
     * default handler shows only the outer message, which is always the
     * unhelpful "Oh shoot! An unknown error occurred."
     */
    private function _logApiFailure(Throwable $e, string $method, string $endpoint, array $payload): void
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
            'type' => (string)$this->entityType,
            'detail' => $detail,
            'payload' => Json::encode($payload),
        ]), true);
    }

    /**
     * Records the Beacon ID of every record written, so submissions can be
     * reconciled against the CRM later.
     */
    private function _logSuccess(array $response, string $method): void
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
            'type' => (string)$this->entityType,
            'id' => (string)$id,
        ]));
    }

    /**
     * Builds the mapping rows for one record type.
     *
     * The library decides what can be written — read-only, smart and rollup
     * fields are computed by Beacon and rejected on write, and file, user and
     * location fields need more than a single mapped value can carry.
     */
    private function _getFields(EntityType $entityType): array
    {
        $integrationFields = [];

        foreach ($entityType->mappableFields() as $field) {
            // A person name is a structured object, so expose one mapping row
            // per name part and reassemble it when sending.
            if ($field->isPersonName()) {
                foreach (BeaconField::NAME_PARTS as $part) {
                    $integrationFields[] = new IntegrationField([
                        'handle' => $field->key . BeaconField::PART_SEPARATOR . $part,
                        'name' => $field->label . ' (' . ucfirst($part) . ')',
                        'type' => IntegrationField::TYPE_STRING,
                        'sourceType' => $field->rawType,
                    ]);
                }

                continue;
            }

            $integrationFields[] = new IntegrationField([
                'handle' => $field->key,
                'name' => $field->label,
                'type' => $this->_convertFieldType($field),
                'sourceType' => $field->rawType,
                'options' => $this->_getFieldOptions($field),
            ]);
        }

        return $integrationFields;
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
     * The mapping rows for the currently selected record type, used for both
     * validation and payload shaping.
     */
    private function _getSelectedTypeFields(bool $allowFetch = false): array
    {
        if (!$this->entityType) {
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

        $entityType = ArrayHelper::firstWhere($entityTypes, 'id', $this->entityType);

        return $entityType['fields'] ?? [];
    }

    /**
     * Turns flat mapped values into an entity payload.
     *
     * The library shapes each value for its Beacon field type — names become
     * objects, emails and phones become arrays of objects, drop-downs and record
     * links become arrays, currency becomes an object, and numeric fields become
     * JSON numbers. The Beacon type of each field comes from the `sourceType`
     * stored on the mapping row, so no schema call is needed to send.
     */
    private function _buildPayload(array $values, array $fieldDefs): EntityPayload
    {
        $fields = ArrayHelper::index($fieldDefs, 'handle');

        // Person-name parts are assembled by the payload builder rather than
        // shaped individually, so the resolver only ever sees a whole field.
        return EntityPayload::resolvedBy(
            static fn(string $key): ?FieldType => FieldType::tryFromName($fields[$key]->sourceType ?? null),
        )->setMany($values);
    }
}
