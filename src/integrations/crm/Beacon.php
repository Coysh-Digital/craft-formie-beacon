<?php

namespace coyshdigital\formiebeacon\integrations\crm;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use GuzzleHttp\Client;
use Throwable;
use verbb\formie\base\Crm;
use verbb\formie\base\Integration;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\helpers\ArrayHelper;
use verbb\formie\models\IntegrationField;
use verbb\formie\models\IntegrationFormSettings;
use verbb\formie\models\Stencil;

class Beacon extends Crm
{
    // Constants
    // =========================================================================

    /**
     * Beacon field types that cannot be written through a plain entity payload.
     *
     * `file` needs Beacon's signed-upload handshake, `user` refers to Beacon
     * user accounts rather than form data, and `location` expects a structured
     * address object that a single mapped form field cannot express.
     */
    public const UNSUPPORTED_FIELD_TYPES = ['file', 'user', 'location'];


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
            $response = $this->request('GET', 'entity_types');
            $entityTypes = $response['results'] ?? [];

            foreach ($entityTypes as $entityType) {
                $key = $entityType['key'] ?? null;

                if (!$key) {
                    continue;
                }

                $settings['entityTypes'][] = [
                    'id' => $key,
                    'name' => $entityType['label'] ?? $key,
                    'fields' => $this->_getFields($entityType['fields'] ?? []),
                ];
            }

            // Present record types alphabetically — Beacon returns them in an
            // arbitrary order that puts custom types before Person.
            if (!empty($settings['entityTypes'])) {
                usort($settings['entityTypes'], fn($a, $b) => strcasecmp($a['name'], $b['name']));
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

            $values = $this->getFieldMappingValues($submission, $this->fieldMapping, $fields);
            $entity = $this->_prepPayload($values, $fields);

            if (!$entity) {
                Integration::error($this, Craft::t('formie', 'No mapped values to send to {name}.', [
                    'name' => static::displayName(),
                ]), true);

                return true;
            }

            $endpoint = 'entity/' . $this->entityType;
            $method = 'POST';
            $payload = $entity;

            if ($this->useUpsert && $this->primaryFieldKey) {
                // Beacon matches an existing record on `primary_field_key`, so
                // that key must also be present in the entity body itself.
                if (!array_key_exists($this->primaryFieldKey, $entity)) {
                    Integration::error($this, Craft::t('formie', 'Upsert key “{key}” is not mapped, so no record can be matched. Map it or disable upsert.', [
                        'key' => $this->primaryFieldKey,
                    ]), true);

                    return false;
                }

                $endpoint .= '/upsert';
                $method = 'PUT';
                $payload = [
                    'primary_field_key' => $this->primaryFieldKey,
                    'entity' => $entity,
                ];
            }

            $response = $this->deliverPayload($submission, $endpoint, $payload, $method);

            if ($response === false) {
                return true;
            }

            $recordId = $response['entity']['id'] ?? null;

            if (!$recordId) {
                Integration::error($this, Craft::t('formie', 'Missing return “id” {response}. Sent payload {payload}', [
                    'response' => Json::encode($response),
                    'payload' => Json::encode($payload),
                ]), true);

                return false;
            }
        } catch (Throwable $e) {
            Integration::apiError($this, $e);

            return false;
        }

        return true;
    }

    public function fetchConnection(): bool
    {
        try {
            $this->request('GET', 'entity_types');
        } catch (Throwable $e) {
            Integration::apiError($this, $e);

            return false;
        }

        return true;
    }


    // Protected Methods
    // =========================================================================

    protected function defineClient(): Client
    {
        $accountId = App::parseEnv($this->accountId);

        return Craft::createGuzzleClient([
            'base_uri' => "https://api.beaconcrm.org/v1/account/{$accountId}/",
            'headers' => [
                'Content-Type' => 'application/json',
                'Beacon-Application' => 'developer_api',
                'Authorization' => 'Bearer ' . App::parseEnv($this->apiKey),
            ],
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
     * Builds the mapping rows for one record type's fields.
     */
    private function _getFields(array $fields): array
    {
        $integrationFields = [];

        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            $type = $field['type'] ?? null;

            if (!$key || !$type) {
                continue;
            }

            // Smart fields, rollups and auto-increments are computed by Beacon
            // and rejected on write.
            if (($field['is_read_only'] ?? false) || ($field['is_smart_field'] ?? false) || ($field['is_rollup_field'] ?? false)) {
                continue;
            }

            if (in_array($type, self::UNSUPPORTED_FIELD_TYPES, true)) {
                continue;
            }

            // A person name is a structured object, so expose one mapping row
            // per name part and reassemble it when sending.
            if ($type === 'person_name') {
                foreach (['full', 'first', 'last', 'middle', 'prefix'] as $part) {
                    $integrationFields[] = new IntegrationField([
                        'handle' => $key . ':' . $part,
                        'name' => ($field['label'] ?? $key) . ' (' . ucfirst($part) . ')',
                        'type' => IntegrationField::TYPE_STRING,
                        'sourceType' => $type,
                    ]);
                }

                continue;
            }

            $integrationFields[] = new IntegrationField([
                'handle' => $key,
                'name' => $field['label'] ?? $key,
                'type' => $this->_convertFieldType($field),
                'sourceType' => $type,
                'options' => $this->_getFieldOptions($field),
            ]);
        }

        return $integrationFields;
    }

    private function _convertFieldType(array $field): string
    {
        $type = $field['type'] ?? '';
        $metadata = $field['metadata'] ?? [];

        return match ($type) {
            'number', 'rating' => IntegrationField::TYPE_NUMBER,
            'currency', 'percent' => IntegrationField::TYPE_FLOAT,
            'boolean' => IntegrationField::TYPE_BOOLEAN,
            'date' => ($metadata['include_time'] ?? false) ? IntegrationField::TYPE_DATETIME : IntegrationField::TYPE_DATE,
            'phone' => IntegrationField::TYPE_PHONE,
            'reference' => IntegrationField::TYPE_ARRAY,
            'select' => ($metadata['allow_multiple'] ?? false) ? IntegrationField::TYPE_ARRAY : IntegrationField::TYPE_STRING,
            default => IntegrationField::TYPE_STRING,
        };
    }

    /**
     * Surfaces a drop-down's configured values so they can be picked in the
     * mapping UI rather than typed by hand. Beacon rejects any value that is
     * not configured for the field.
     */
    private function _getFieldOptions(array $field): array
    {
        if (($field['type'] ?? '') !== 'select') {
            return [];
        }

        $options = $field['metadata']['options'] ?? [];

        if (!$options) {
            return [];
        }

        return [
            'label' => $field['label'] ?? '',
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
     * Turns flat mapped values into the shapes Beacon expects: names become
     * objects, emails and phones become arrays of objects, drop-downs and
     * record links become arrays, and numeric fields become JSON numbers.
     */
    private function _prepPayload(array $values, array $fieldDefs): array
    {
        $fields = ArrayHelper::index($fieldDefs, 'handle');
        $payload = [];
        $names = [];

        foreach ($values as $handle => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // Person-name parts are collected and merged into one object below.
            if (str_contains($handle, ':')) {
                [$key, $part] = explode(':', $handle, 2);
                $names[$key][$part] = $value;

                continue;
            }

            $field = $fields[$handle] ?? null;
            $sourceType = $field->sourceType ?? 'string';

            $payload[$handle] = match ($sourceType) {
                'email' => [['email' => $value, 'is_primary' => true]],
                'phone' => [['number' => (string)$value, 'is_primary' => true]],
                'boolean' => (bool)$value,
                // Currency is the one numeric type Beacon wants as an object.
                // A bare number is accepted with a 200 but silently stored as
                // null, so the amount would be lost without this. The currency
                // code is omitted deliberately: Beacon fills in the account
                // default. Amounts are major units (25.5 means £25.50).
                'currency' => ['value' => (float)$value],
                // Number, percent and rating all reject the object form.
                // `+ 0` keeps whole numbers as ints and decimals as floats,
                // respecting each field's configured decimal places.
                'number', 'rating', 'percent' => is_numeric($value) ? $value + 0 : $value,
                // Drop-downs are arrays in Beacon even when single-select.
                'select' => array_values(array_filter((array)$value, fn($v) => $v !== null && $v !== '')),
                // Record links are arrays of integer Beacon record IDs.
                'reference' => array_values(array_map('intval', array_filter((array)$value))),
                default => $value,
            };
        }

        foreach ($names as $key => $parts) {
            // Beacon shows `full` throughout its UI, so derive it when only the
            // individual parts have been mapped.
            if (empty($parts['full'])) {
                $derived = trim(implode(' ', array_filter([
                    $parts['first'] ?? null,
                    $parts['middle'] ?? null,
                    $parts['last'] ?? null,
                ])));

                if ($derived) {
                    $parts['full'] = $derived;
                }
            }

            $payload[$key] = array_merge([
                'full' => null,
                'first' => null,
                'last' => null,
                'middle' => null,
                'prefix' => null,
            ], $parts);
        }

        return $payload;
    }
}
