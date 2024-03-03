<?php
namespace verbb\formie\base;

use verbb\formie\Formie;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\events\ModifyFieldConfigEvent;
use verbb\formie\events\ModifyFieldEmailValueEvent;
use verbb\formie\events\ModifyFieldHtmlTagEvent;
use verbb\formie\events\ModifyFieldIntegrationValueEvent;
use verbb\formie\events\ModifyFieldUniqueQueryEvent;
use verbb\formie\events\ModifyFieldValueEvent;
use verbb\formie\fields;
use verbb\formie\fields\Hidden;
use verbb\formie\helpers\ArrayHelper;
use verbb\formie\helpers\ConditionsHelper;
use verbb\formie\helpers\Html;
use verbb\formie\helpers\SchemaHelper;
use verbb\formie\helpers\StringHelper;
use verbb\formie\helpers\Table;
use verbb\formie\helpers\Variables;
use verbb\formie\models\FieldLayout;
use verbb\formie\models\FieldLayoutPage;
use verbb\formie\models\FieldLayoutRow;
use verbb\formie\models\IntegrationField;
use verbb\formie\models\Notification;
use verbb\formie\models\HtmlTag;
use verbb\formie\models\Settings;
use verbb\formie\positions\AboveInput;
use verbb\formie\positions\BelowInput;
use verbb\formie\positions\Hidden as HiddenPosition;
use verbb\formie\records\Field as FieldRecord;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field as CraftField;
use craft\base\FieldInterface as CraftFieldInterface;
use craft\base\SavableComponent;
use craft\db\Query;
use craft\fieldlayoutelements\CustomField;
use craft\fields\BaseRelationField;
use craft\gql\types\DateTime as DateTimeType;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\Template;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;

use GraphQL\Type\Definition\Type;

use Twig\Markup;

use DateTime;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Throwable;

use yii\db\ExpressionInterface;
use yii\db\Schema;

// TODO: Remove `FieldInterface` interface after fields have been migrated from Craft's native table.
abstract class Field extends SavableComponent implements CraftFieldInterface, FieldInterface
{
    // Constants
    // =========================================================================

    public const EVENT_BEFORE_ELEMENT_SAVE = 'beforeElementSave';
    public const EVENT_AFTER_ELEMENT_SAVE = 'afterElementSave';
    public const EVENT_AFTER_ELEMENT_PROPAGATE = 'afterElementPropagate';
    public const EVENT_BEFORE_ELEMENT_DELETE = 'beforeElementDelete';
    public const EVENT_AFTER_ELEMENT_DELETE = 'afterElementDelete';
    public const EVENT_BEFORE_ELEMENT_RESTORE = 'beforeElementRestore';
    public const EVENT_AFTER_ELEMENT_RESTORE = 'afterElementRestore';

    public const EVENT_MODIFY_DEFAULT_VALUE = 'modifyDefaultValue';
    public const EVENT_MODIFY_FIELD_CONFIG = 'modifyFieldConfig';
    public const EVENT_MODIFY_HTML_TAG = 'modifyHtmlTag';
    public const EVENT_MODIFY_VALUE_AS_STRING = 'modifyValueAsString';
    public const EVENT_MODIFY_VALUE_AS_JSON = 'modifyValueAsJson';
    public const EVENT_MODIFY_VALUE_FOR_EXPORT = 'modifyValueForExport';
    public const EVENT_MODIFY_VALUE_FOR_INTEGRATION = 'modifyValueForIntegration';
    public const EVENT_MODIFY_VALUE_FOR_SUMMARY = 'modifyValueForSummary';
    public const EVENT_MODIFY_VALUE_FOR_EMAIL = 'modifyValueForEmail';
    public const EVENT_MODIFY_UNIQUE_QUERY = 'modifyUniqueQuery';

    public const TRANSLATION_METHOD_NONE = 'none';
    public const TRANSLATION_METHOD_SITE = 'site';
    public const TRANSLATION_METHOD_SITE_GROUP = 'siteGroup';
    public const TRANSLATION_METHOD_LANGUAGE = 'language';
    public const TRANSLATION_METHOD_CUSTOM = 'custom';


    // Traits
    // =========================================================================

    // TODO: remove when we remove the `FieldInterface` interface
    use FieldLegacy;


    // Static Methods
    // =========================================================================

    public static function phpType(): string
    {
        return 'mixed';
    }

    public static function dbType(): array|string|null
    {
        return Schema::TYPE_TEXT;
    }

    public static function getFrontEndInputTemplatePath(): string
    {
        return 'fields/' . static::_getKebabName();
    }

    public static function getEmailTemplatePath(): string
    {
        return 'fields/' . static::_getKebabName();
    }

    public static function getSvgIcon(): string
    {
        if (static::getSvgIconPath()) {
            return Craft::$app->getView()->renderTemplate(static::getSvgIconPath());
        }

        return '';
    }

    public static function getSvgIconPath(): string
    {
        return '';
    }

    public static function getRequiredPlugins(): array
    {
        return [];
    }


    // Properties
    // =========================================================================

    public ?int $layoutId = null;
    public ?int $pageId = null;
    public ?int $rowId = null;
    public ?int $syncId = null;
    public ?string $label = null;
    public ?string $handle = null;
    public ?int $sortOrder = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $dateUpdated = null;
    public ?string $uid = null;

    public ?string $instructions = null;
    public bool $required = false;
    public ?string $matchField = null;
    public ?string $placeholder = null;
    public mixed $defaultValue = null;
    public ?string $prePopulate = null;
    public ?string $errorMessage = null;
    public ?string $labelPosition = null;
    public ?string $instructionsPosition = null;
    public ?string $cssClasses = null;
    public ?array $containerAttributes = null;
    public ?array $inputAttributes = null;
    public bool $includeInEmail = true;
    public ?string $emailValue = null;
    public bool $enableConditions = false;
    public ?array $conditions = null;
    public bool $enableContentEncryption = false;
    public ?string $visibility = null;

    private ?Form $_form = null;
    private ?FieldLayout $_layout = null;
    private ?FieldLayoutPage $_page = null;
    private ?FieldLayoutRow $_row = null;
    private array $_themeConfig = [];
    private ?FieldInterface $_parentField = null;
    private string $_namespace = 'fields';
    private ?string $_customNamespace = null;


    // Public Methods
    // =========================================================================

    public function __construct(array $config = [])
    {
        // Config normalization
        self::normalizeConfig($config);

        parent::__construct($config);
    }

    public function settingsAttributes(): array
    {
        $names = parent::settingsAttributes();
        $names[] = 'instructions';
        $names[] = 'required';
        $names[] = 'matchField';
        $names[] = 'placeholder';
        $names[] = 'defaultValue';
        $names[] = 'prePopulate';
        $names[] = 'errorMessage';
        $names[] = 'labelPosition';
        $names[] = 'instructionsPosition';
        $names[] = 'cssClasses';
        $names[] = 'containerAttributes';
        $names[] = 'inputAttributes';
        $names[] = 'includeInEmail';
        $names[] = 'emailValue';
        $names[] = 'enableConditions';
        $names[] = 'conditions';
        $names[] = 'enableContentEncryption';
        $names[] = 'visibility';

        return $names;
    }

    public function getType(): string
    {
        return static::class;
    }

    public function getDisplayType(): ?string
    {
        if (property_exists($this, 'displayType')) {
            return $this->displayType;
        }

        return null;
    }

    public function hasLabel(): bool
    {
        return true;
    }

    public function hasSubFields(): bool
    {
        return false;
    }

    public function hasNestedFields(): bool
    {
        return false;
    }

    public function getIsCosmetic(): bool
    {
        return false;
    }

    public function getIsHidden(): bool
    {
        return $this->visibility === 'hidden';
    }

    public function getIsNested(): bool
    {
        return (bool)$this->getParentField();
    }

    public function getForm(): ?Form
    {
        if ($this->_form || !$this->layoutId) {
            return $this->_form;
        }

        return $this->_form = Formie::$plugin->getForms()->getFormByLayoutId($this->layoutId);
    }

    public function getLayout(): ?FieldLayout
    {
        if ($this->_layout || !$this->layoutId) {
            return $this->_layout;
        }

        return $this->_layout = Formie::$plugin->getFields()->getLayoutById($this->layoutId);
    }

    public function getPage(): ?FieldLayoutPage
    {
        if ($this->_page || !$this->pageId) {
            return $this->_page;
        }

        return $this->_page = Formie::$plugin->getFields()->getPageById($this->pageId);
    }

    public function getRow(): ?FieldLayoutRow
    {
        if ($this->_row || !$this->rowId) {
            return $this->_row;
        }

        return $this->_row = Formie::$plugin->getFields()->getRowById($this->rowId);
    }

    public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
    {
        return $this->normalizeValue($value, $element);
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        // Check if the string contains a previously encrypted version, or the field is enabled
        // This might occur if the field was set to encrypted, but changed later. We still need to
        // decrypt field content
        if (is_string($value)) {
            if ($this->enableContentEncryption || str_contains($value, 'base64:')) {
                $value = StringHelper::decdec($value);
            }
        }

        return $value;
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof Serializable) {
            // If the object explicitly defines its savable value, use that
            $value = $value->serialize();
        } else if ($value instanceof Arrayable) {
            // If it's "arrayable", convert to array
            $value = $value->toArray();
        } else if ($value instanceof DateTime || DateTimeHelper::isIso8601($value)) {
            // Only DateTime objects and ISO-8601 strings should automatically be detected as dates
            $value = Db::prepareDateForDb($value);
        }

        // Handle if we need to save field content as encrypted
        if ($this->enableContentEncryption && is_string($value)) {
            $value = StringHelper::encenc($value);
        }

        return $value;
    }

    public function isValueEmpty(mixed $value, ?ElementInterface $element): bool
    {
        // Default to yii\validators\Validator::isEmpty()'s behavior
        return $value === null || $value === [] || $value === '';
    }

    public function getValueAsString(mixed $value, ?ElementInterface $element = null): mixed
    {
        $value = $this->defineValueAsString($value, $element);

        $event = new ModifyFieldValueEvent([
            'value' => $value,
            'field' => $this,
            'submission' => $element,
        ]);

        $this->trigger(static::EVENT_MODIFY_VALUE_AS_STRING, $event);

        return $event->value;
    }

    public function getValueAsJson(mixed $value, ?ElementInterface $element = null): mixed
    {
        $value = $this->defineValueAsJson($value, $element);

        $event = new ModifyFieldValueEvent([
            'value' => $value,
            'field' => $this,
            'submission' => $element,
        ]);

        $this->trigger(static::EVENT_MODIFY_VALUE_AS_JSON, $event);

        return $event->value;
    }

    public function getValueForExport(mixed $value, ?ElementInterface $element = null): mixed
    {
        $value = $this->defineValueForExport($value, $element);

        $event = new ModifyFieldValueEvent([
            'value' => $value,
            'field' => $this,
            'submission' => $element,
        ]);

        $this->trigger(static::EVENT_MODIFY_VALUE_FOR_EXPORT, $event);

        return $event->value;
    }

    public function getValueForIntegration(mixed $value, IntegrationField $integrationField, IntegrationInterface $integration, ?ElementInterface $element = null, string $fieldKey = ''): mixed
    {
        $value = $this->defineValueForIntegration($value, $integrationField, $integration, $element, $fieldKey);

        $event = new ModifyFieldIntegrationValueEvent([
            'value' => $value,
            'field' => $this,
            'submission' => $element,
            'integrationField' => $integrationField,
            'integration' => $integration,
        ]);

        $this->trigger(static::EVENT_MODIFY_VALUE_FOR_INTEGRATION, $event);

        // Raise the same event on the integration class for convenience
        if ($integration) {
            $integration->init(); // We need to manually trigger `init()` as it doesn't seem to kick off in a queue job
            $integration->trigger($integration::EVENT_MODIFY_FIELD_MAPPING_VALUE, $event);
        }

        return $event->value;
    }

    public function getValueForSummary(mixed $value, ?ElementInterface $element = null): mixed
    {
        $value = $this->defineValueForSummary($value, $element);

        $event = new ModifyFieldValueEvent([
            'value' => $value,
            'field' => $this,
            'submission' => $element,
        ]);

        $this->trigger(static::EVENT_MODIFY_VALUE_FOR_SUMMARY, $event);

        return $event->value;
    }

    public function getValueForEmail(mixed $value, Notification $notification, ?ElementInterface $element = null): mixed
    {
        $value = $this->defineValueForEmail($value, $notification, $element);

        $event = new ModifyFieldEmailValueEvent([
            'value' => $value,
            'field' => $this,
            'submission' => $element,
            'notification' => $notification,
        ]);

        $this->trigger(static::EVENT_MODIFY_VALUE_FOR_EMAIL, $event);

        return $event->value;
    }

    public function populateValue(mixed $value): void
    {
        $this->defaultValue = $this->normalizeValue($value);
    }

    public function getElementValidationRules(): array
    {
        $rules = [];

        if ($this->matchField) {
            $rules[] = ['validateMatchField', 'skipOnEmpty' => false];
        }

        return $rules;
    }

    public function validateMatchField(ElementInterface $element): void
    {
        $fieldHandle = str_replace(['{', '}'], '', $this->matchField);
        $sourceValue = $element->getFieldValue($fieldHandle);
        $value = $element->getFieldValue($this->fieldKey);

        if ($sourceValue !== $value) {
            $sourceField = $element->getFieldByHandle($fieldHandle);

            $element->addError($this->fieldKey, Craft::t('formie', '{name} must match {value}.', [
                'name' => $this->label,
                'value' => $sourceField->label ?? '',
            ]));
        }
    }

    public function validateUniqueValue(ElementInterface $element): void
    {
        $value = $element->getFieldValue($this->fieldKey);
        $value = trim($value);

        // Use a DB lookup for performance
        $query = Craft::$app->getDb()->getQueryBuilder()->jsonContains('s.content', [$this->uid => $value]);

        $query = (new Query())
            ->from(['s' => Table::FORMIE_SUBMISSIONS])
            ->where([$query, 'isIncomplete' => false, 'e.dateDeleted' => null])
            ->leftJoin('{{%elements}} e', '[[e.id]] = [[s.id]]');

        // Exclude _this_ element, if there is one
        if ($element->id) {
            $query->andWhere(['!=', 's.id', $element->id]);
        }

        $event = new ModifyFieldUniqueQueryEvent([
            'query' => $query,
            'field' => $this,
        ]);

        // Fire a 'modifyFieldUniqueQuery' event
        $this->trigger(self::EVENT_MODIFY_UNIQUE_QUERY, $event);

        // Be sure to check only against completed submission content
        $valueExists = $event->query->exists();

        if ($valueExists) {
            $element->addError($this->fieldKey, Craft::t('formie', '“{name}” must be unique.', [
                'name' => $this->label,
            ]));
        }
    }

    public function getHtmlId(Form $form, ?string $extra = null): string
    {
        // Return the `id` attribute for the field, including parent fields
        // `fui-contactForm-xpvgyvsp-singleName` or `fui-contactForm-xpvgyvsp-multiName-firstName`
        $ids = [$form->getFormId(), ...$this->getFullNamespace(), $this->handle, $extra];

        return Html::getInputIdAttribute(ArrayHelper::filterEmpty($ids));
    }

    public function getHtmlDataId(Form $form, ?string $extra = null): string
    {
        // Return the `data-id` attribute for the field, including parent fields
        // `contactForm-singleName` or `contactForm-multiName-firstName`
        $ids = [$form->handle, ...$this->getFullHandle(), $extra];

        return implode('-', ArrayHelper::filterEmpty($ids));
    }

    public function getHtmlName(?string $extra = null): string
    {
        // Return the `name` attribute for the field, including parent fields
        // `fields[singleName]` or `fields[multiName][firstName]`
        $names = [...$this->getFullNamespace(), $this->handle, $extra];

        // Remove empty items, but allow `0` for namespaces
        $names = ArrayHelper::filterEmpty($names);

        return Html::getInputNameAttribute($names);
    }

    public function getFieldKey(): string
    {
        // Return the full value path for a field, including any parents in dot-notation.
        // `singlename` or `multiName.firstName` or `group.text` or `repeater.0.text`
        // This is to assist with submission content lookup, or submission errors and should be used
        // instead of the simple `field.handle`, as it factors in the parent field and custom namespace
        $names = [];

        foreach ($this->getFullNamespace() as $namespaceKey => $item) {
            // We don't care about `fields`, we just want field info
            if ($item === 'fields') {
                continue;
            }

            // Convert any nested `repeater[0]` references in the namespace to be proper arrays
            $names[] = explode('[', str_replace(']', '', $item));
        }

        // Flatten the array (for performance)
        $names = array_merge(...$names);

        // Remove empty items, but allow `0` for namespaces
        $names = ArrayHelper::filterEmpty([...$names, $this->handle]);
        
        return implode('.', $names);
    }

    public function getFieldTypeConfig(): array
    {
        $config = [
            'icon' => static::getSvgIcon(),
            'type' => get_class($this),
            'label' => static::displayName(),
            'preview' => $this->getPreviewInputHtml(),
            'hasLabel' => $this->hasLabel(),
            'hasSubFields' => $this->hasSubFields(),
            'hasNestedFields' => $this->hasNestedFields(),
            'schema' => $this->getFieldSchema(),
            'labelPositions' => Formie::$plugin->getFields()->getLabelPositionsOptions($this),
            'instructionsPositions' => Formie::$plugin->getFields()->getInstructionsPositionsOptions($this),

            // Load in the regular field data, but for a new field
            'newField' => $this->getFormBuilderConfig(),

            // Add in any extra data the field settings require
            'data' => $this->getFieldTypeConfigData(),
        ];

        return $config;
    }

    public function getFieldTypeConfigData(): array
    {
        return [];
    }

    public function getFormBuilderConfig(): array
    {
        $config = [
            'type' => get_class($this),
            'id' => $this->id,
            'errors' => $this->getErrors(),
            'hasLabel' => $this->hasLabel(),
            'hasSubFields' => $this->hasSubFields(),
            'hasNestedFields' => $this->hasNestedFields(),
            'hasConditions' => $this->hasConditions(),
            'isCosmetic' => $this->getIsCosmetic(),
            'isSynced' => $this->getIsSynced(),
            'isNested' => $this->getIsNested(),

            // Any writeable settings should be in `settings` to work with FormKit.
            'settings' => $this->getFormBuilderSettings(),
        ];

        // Allow fields to provide subField options for mapping
        if ($this instanceof SubFieldInterface) {
            $config['subFieldOptions'] = $this->getSubFieldOptions();
        }

        // Whether this is an element field
        if ($this instanceof ElementFieldInterface) {
            $config['isElementField'] = true;
        }

        // Fire a 'modifyFieldConfig' event
        $event = new ModifyFieldConfigEvent([
            'config' => $config,
        ]);
        $this->trigger(self::EVENT_MODIFY_FIELD_CONFIG, $event);

        return $event->config;
    }

    public function getFormBuilderSettings(): array
    {
        $settings = $this->getSettings();
        $settings['layoutId'] = $this->layoutId;
        $settings['pageId'] = $this->pageId;
        $settings['rowId'] = $this->rowId;
        $settings['syncId'] = $this->syncId;
        $settings['label'] = $this->label;
        $settings['handle'] = $this->handle;
        $settings['required'] = $this->required;
        $settings['instructions'] = $this->instructions;

        return $settings;
    }

    public function getDefaultValue(string $attributePrefix = ''): mixed
    {
        $defaultValue = null;
        $defaultValueAttribute = 'defaultValue';
        $prePopulateAttribute = 'prePopulate';

        // Handle nested fields that supply their own attribute to fetch default values from
        if ($attributePrefix) {
            $defaultValueAttribute = "{$attributePrefix}DefaultValue";
            $prePopulateAttribute = "{$attributePrefix}PrePopulate";
        }

        // Check for a query string is configured
        if ($this->$prePopulateAttribute) {
            $queryParam = Craft::$app->getRequest()->getParam($this->$prePopulateAttribute);

            if ($queryParam !== null) {
                $defaultValue = $this->setPrePopulatedValue($queryParam);
            }
        }

        if (!$defaultValue) {
            $defaultValue = $this->$defaultValueAttribute;

            // Parse the default value for variables
            if (!is_array($defaultValue) && !is_object($defaultValue)) {
                // Don't do this for a hidden field, as we want to retain variable until the form it submitted,
                // to evaluate there. As such, the default value is more or less the value of the field.
                if (!($this instanceof Hidden)) {
                    $defaultValue = Variables::getParsedValue($defaultValue);
                }
            }
        }

        $event = new ModifyFieldValueEvent([
            'value' => $defaultValue,
            'field' => $this,
        ]);

        $this->trigger(static::EVENT_MODIFY_DEFAULT_VALUE, $event);

        return $event->value;
    }

    public function getFieldSchema(): array
    {
        $tabs = [];
        $fields = [];

        // Define the tabs we have for editing a field. Only these can be used.
        $definedTabs = [
            'General',
            'Settings',
            'Appearance',
            'Advanced',
            'Conditions',
        ];

        foreach ($definedTabs as $definedTab) {
            $methodName = 'define' . $definedTab . 'Schema';

            if (method_exists($this, $methodName)) {
                if ($fieldSchema = $this->$methodName()) {
                    $tabLabel = Craft::t('formie', $definedTab);

                    // Add `name` and `id` attributes automatically for every FormKit input
                    SchemaHelper::setFieldAttributes($fieldSchema);

                    $fields[] = [
                        '$cmp' => 'TabPanel',
                        'attrs' => [
                            'data-tab-panel' => $tabLabel,
                        ],
                        'children' => $fieldSchema,
                    ];

                    $tabs[] = [
                        'label' => $tabLabel,
                        'fields' => SchemaHelper::extractFieldsFromSchema($fieldSchema),
                    ];
                }
            }
        }

        // Return the DOM schema for Vue to render
        return [
            'tabs' => $tabs,
            'fields' => [
                [
                    '$cmp' => 'TabPanels',
                    'attrs' => [
                        'class' => 'fui-modal-content',
                    ],
                    'children' => $fields,
                ],
            ],
        ];
    }

    public function renderHtmlTag(string $key, array $context = []): ?HtmlTag
    {
        // Get the HtmlTag definition
        $tag = $this->defineHtmlTag($key, $context);

        if ($tag) {
            // The render options are stored on the form for efficiency, so they're only parsed once
            // even if passing in options via `craft.formie.renderField()`.
            $form = $context['form'] ?? $this->getForm();
            
            // Find if there's a config option for this key, either in plugin config or template render options
            $templateConfig = $form->getThemeConfigItem($key);

            // Check if this is a class-specific key (e.g. `singleLineText`) which will take precedence over
            // more general config, and merge them.
            $classTemplateConfig = $form->getThemeConfigItem(Html::getFieldClassKey($this) . '.' . $key);
            $config = Html::mergeHtmlConfigs([$key => $templateConfig], [$key => $classTemplateConfig])[$key] ?? [];

            // Check if the config is falsey - then don't render
            if ($config === false || $config === null) {
                $tag = null;
            } else {
                // Are we resetting classes globally?
                if ($form->resetClasses) {
                    $config['resetClass'] = true;
                }

                $tag->setFromConfig($config, $context);
            }
        }

        $event = new ModifyFieldHtmlTagEvent([
            'field' => $this,
            'tag' => $tag,
            'key' => $key,
            'context' => $context,
        ]);

        $this->trigger(static::EVENT_MODIFY_HTML_TAG, $event);

        return $event->tag;
    }

    public function defineHtmlTag(string $key, array $context = []): ?HtmlTag
    {
        $form = $context['form'] ?? null;
        $submission = $context['element'] ?? null;
        $errors = $context['errors'] ?? null;

        $id = $this->getHtmlId($form);
        $dataId = $this->getHtmlDataId($form);

        if ($key === 'field') {
            $labelPosition = $context['labelPosition'] ?? null;
            $subFieldLabelPosition = $context['subFieldLabelPosition'] ?? null;
            $instructionsPosition = $context['instructionsPosition'] ?? null;
            $containerAttributes = $this->getContainerAttributes() ?? [];

            return new HtmlTag('div', [
                'class' => [
                    'fui-field',
                    'fui-type-' . StringHelper::toKebabCase($this->displayName()),
                    'fui-label-' . $labelPosition,
                    'fui-subfield-label-' . $subFieldLabelPosition,
                    'fui-instructions-' . $instructionsPosition,
                    $errors ? 'fui-field-error fui-error' : null,
                    $this->required ? 'fui-field-required' : null,
                    $this->getIsHidden() ? 'fui-hidden' : null,
                    $this->getParentField() ? 'fui-' . StringHelper::toKebabCase($this->getParentField()->displayName() . ' ' . $this->handle) : 'fui-page-field',
                ],
                'data' => [
                    'field-handle' => $this->handle,
                    'field-type' => StringHelper::toKebabCase($this->displayName()),
                    'field-display-type' => $this->getDisplayType(),
                    'field-config' => $this->getConfigJson(),
                    'field-conditions' => $this->getConditionsJson($submission),
                ],
            ], $containerAttributes, $this->cssClasses);
        }

        if ($key === 'fieldContainer') {
            return new HtmlTag('div', [
                'class' => 'fui-field-container',
            ]);
        }

        if ($key === 'fieldLabel') {
            if (!$this->hasLabel()) {
                return null;
            }

            $labelPosition = $context['labelPosition'] ?? null;

            return new HtmlTag('label', [
                'class' => [
                    'fui-label',
                ],
                'data' => [
                    'fui-sr-only' => $labelPosition instanceof HiddenPosition ? true : false,
                ],
                'for' => $id,
            ]);
        }

        if ($key === 'fieldRequired') {
            return new HtmlTag('span', [
                'class' => 'fui-required',
                'aria-hidden' => 'true',
            ]);
        }

        if ($key === 'fieldOptional') {
            return new HtmlTag('span', [
                'class' => 'fui-optional',
            ]);
        }

        if ($key === 'fieldInstructions') {
            return new HtmlTag('div', [
                'id' => "{$id}-instructions",
                'class' => 'fui-instructions',
            ]);
        }

        if ($key === 'fieldInputWrapper') {
            return new HtmlTag('div', [
                'class' => 'fui-input-wrapper',
            ]);
        }

        if ($key === 'fieldErrors') {
            return new HtmlTag('ul', [
                'class' => 'fui-errors',
            ]);
        }

        if ($key === 'fieldError') {
            return new HtmlTag('li', [
                'class' => 'fui-error-message',
            ]);
        }

        if ($key === 'subFieldRows') {
            return new HtmlTag('div', [
                'class' => 'fui-field-rows',
            ]);
        }

        if ($key === 'subFieldRow') {
            return new HtmlTag('div', [
                'class' => 'fui-row',
            ]);
        }

        if ($key === 'nestedFieldRows') {
            return new HtmlTag('div', [
                'class' => 'fui-field-rows',
            ]);
        }

        if ($key === 'nestedFieldRow') {
            return new HtmlTag('div', [
                'class' => 'fui-row',
            ]);
        }

        return null;
    }

    public function getContainerAttributes(): array
    {
        if (!$this->containerAttributes) {
            return [];
        }

        return ArrayHelper::map($this->containerAttributes, 'label', 'value');
    }

    public function getInputAttributes(): array
    {
        if (!$this->inputAttributes) {
            return [];
        }

        return ArrayHelper::map($this->inputAttributes, 'label', 'value');
    }

    public function getNamespace(): string
    {
        return $this->_namespace;
    }

    public function setNamespace(string|bool|null $value): void
    {
        $this->_namespace = $value;
    }

    public function getParentField(): ?FieldInterface
    {
        return $this->_parentField;
    }

    public function setParentField(FieldInterface $value, string $namespace = ''): void
    {
        $this->_parentField = $value;

        // Also, set the namespace (on the parent field), commonly just the field handle
        // But allows it to be added to (think Repeater).
        // Be sure to create a valid name attribute, from `fieldHandle` and `some[more][attrs]`
        // to `fieldHandle[some][some][attrs]`. Also allow `0` as a namespace.
        if ($namespace !== '') {
            $this->setNamespace(Html::namespaceInputName($namespace, $value->handle));
        } else {
            $this->setNamespace($value->handle);
        }
    }

    public function getCpInputHtml(mixed $value, ?ElementInterface $element): Markup
    {
        $input = $this->cpInputHtml($value, $element, false);
        $errors = $element ? $element->getErrors($this->handle) : '';

        $field = Cp::fieldHtml($input, [
            'label' => Craft::t('site', $this->label),
            'attribute' => $this->handle,
            'required' => $this->required,
            'instructions' => Craft::t('site', $this->instructions),
            'id' => $this->handle,
            'errors' => $errors,
            'fieldAttributes' => [
                'data-type' => get_class($this),
            ],
        ]);

        return Template::raw($field);
    }

    public function getFrontEndInputHtml(Form $form, mixed $value, array $renderOptions = []): Markup
    {
        if (!static::getFrontEndInputTemplatePath()) {
            return Template::raw('');
        }

        $inputOptions = $this->getFrontEndInputOptions($form, $value, $renderOptions);
        $html = $form->renderTemplate(static::getFrontEndInputTemplatePath(), $inputOptions);

        return Template::raw($html);
    }

    public function getFrontEndInputOptions(Form $form, mixed $value, array $renderOptions = []): array
    {
        // Check to see if we're overriding the field
        $field = $renderOptions['field'] ?? $this;

        // Remove some attributes from render options
        $errors = ArrayHelper::remove($renderOptions, 'errors');
        $submission = ArrayHelper::remove($renderOptions, 'submission');

        return [
            'form' => $form,
            'name' => $this->handle,
            'value' => $value,
            'field' => $field,
            'errors' => $errors,
            'submission' => $submission,
            'renderOptions' => $renderOptions,
        ];
    }

    public function applyRenderOptions(Form $form, array $renderOptions = []): void
    {
        /* @var Settings $pluginSettings */
        $pluginSettings = Formie::$plugin->getSettings();

        $this->_customNamespace = $renderOptions['fieldNamespace'] ?? null;

        // Allow the use of falsey namespaces
        if ($this->_customNamespace !== null) {
            $this->setNamespace($this->_customNamespace);
        }

        $templateConfig = $renderOptions['themeConfig'] ?? [];

        if ($templateConfig) {
            $form->setThemeConfig($templateConfig);
        }
    }

    public function getFrontEndJsModules(): ?array
    {
        return null;
    }

    public function getConfigJson(): ?string
    {
        // From the provided JS module config, extract just the settings and module name
        // for use inline in the HTML. We load the scripts async, and rely on the HTML for
        // fields to output their config, so it's reliable and works for on-demand HTML (repeater)
        $modules = $this->getFrontEndJsModules();

        // Normalise to handle multiple module registrations
        if (!isset($modules[0])) {
            $modules = [$modules];
        }

        if ($modules) {
            $config = [];

            foreach ($modules as $module) {
                $settings = $module['settings'] ?? [];
                $settings['module'] = $module['module'] ?? '';
                $settings = array_filter($settings);

                if ($settings) {
                    $config[] = $settings;
                }
            }

            if ($config) {
                return Json::encode($config);
            }
        }

        return null;
    }

    public function getIsSynced(): bool
    {
        return (bool)$this->syncId;
    }

    public function hasConditions(): bool
    {
        return ($this->enableConditions && $this->getConditions());
    }

    public function getConditions(): array
    {
        // Filter out any un-set conditions
        $conditions = $this->conditions ?? [];
        $conditionRows = $conditions['conditions'] ?? [];

        foreach ($conditionRows as $key => $condition) {
            if (!($condition['condition'] ?? null)) {
                unset($conditions['conditions'][$key]);
            }
        }

        return $conditions;
    }

    public function getConditionsJson(): ?string
    {
        if ($this->hasConditions()) {
            $conditionSettings = $this->getConditions();
            $conditions = $conditionSettings['conditions'] ?? [];

            // Ensure that any custom namespace provided in render options works. 
            // Note we can't use `this->_namespace` or any of the `namespace()` functions which will be incorrect
            // when referencing nested fields that use sibling conditions.
            $namespace = $this->_customNamespace ?? 'fields';

            // Prep the conditions for JS
            $conditionSettings['conditions'] = ConditionsHelper::prepConditionsForJs($conditions, $namespace);

            // Mark if this is a nested field within a Group/Repeater.
            $conditionSettings['isNested'] = $this->getIsNested();

            return Json::encode($conditionSettings);
        }

        return null;
    }

    public function isConditionallyHidden(Submission $submission): bool
    {
        $isFieldHidden = false;
        $isPageHidden = false;

        // Check if the field itself is hidden
        if ($this->enableConditions) {
            $conditionSettings = $this->getConditions();
            $conditions = $conditionSettings['conditions'] ?? [];

            if ($conditionSettings && $conditions) {
                // A `true` result means the field passed the evaluation and that it has a value, whilst a `false` result means
                // it didn't (for instance the field doesn't have a value)
                $result = ConditionsHelper::getConditionalTestResult($conditionSettings, $submission);

                // Depending on if we show or hide the field when evaluating. If `false` and set to show, it means
                // the field is hidden and the conditions to show it isn't met. Therefore, report back that this field is hidden.
                if (($result && $conditionSettings['showRule'] !== 'show') || (!$result && $conditionSettings['showRule'] === 'show')) {
                    $isFieldHidden = true;
                }
            }
        }

        // Also check if the field is in a hidden page
        if (!$isFieldHidden && $page = $this->getPage($submission)) {
            $isPageHidden = $page->isConditionallyHidden($submission);
        }

        return $isFieldHidden || $isPageHidden;
    }

    public function getEmailHtml(Submission $submission, Notification $notification, mixed $value, array $renderOptions = []): string|null|bool
    {
        $inputOptions = $this->getEmailOptions($submission, $notification, $value, $renderOptions);
        $html = $notification->renderTemplate(static::getEmailTemplatePath(), $inputOptions);

        return Template::raw($html);
    }

    public function getEmailOptions(Submission $submission, Notification $notification, mixed $value, array $renderOptions = []): array
    {
        return [
            'notification' => $notification,
            'submission' => $submission,
            'name' => $this->handle,
            'value' => $value,
            'field' => $this,
            'renderOptions' => $renderOptions,
        ];
    }

    public function defineGeneralSchema(): array
    {
        return [];
    }

    public function defineSettingsSchema(): array
    {
        return [];
    }

    public function defineAppearanceSchema(): array
    {
        return [];
    }

    public function defineAdvancedSchema(): array
    {
        return [];
    }

    public function afterCreateField(array $data): void
    {

    }

    public function getSettingGqlTypes(): array
    {
        $types = [];
        $excludedProperties = [];

        // Use reflections to grab most (if not all) properties and automate casting. To do this, we need to fetch 
        // properties that are _just_ from the individual classes not any inherited or through traits. The only way 
        // to handle this is to fetch all traits first, and diff them later on.
        $class = new ReflectionClass($this);

        foreach ($class->getTraits() as $trait) {
            foreach ($trait->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $excludedProperties[] = $property->getName();
            }
        }

        $typeMap = [
            'string' => Type::string(),
            'int' => Type::int(),
            'float' => Type::float(),
            'bool' => Type::boolean(),
            'datetime' => DateTimeType::getType(),
        ];

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if (!$property->isStatic() && !$property->getDeclaringClass()->isAbstract() && !in_array($property->getName(), $excludedProperties)) {
                // If we haven't defined mapping, don't assume its value. It'll be up to classes to define these
                $propertyName = $property->getName();

                // Properties can have multiple types
                $propertyType = $property->getType();

                // Handle _some_ union types
                if ($propertyType instanceof ReflectionUnionType) {
                    // Special case for int|float
                    $names = array_map(fn(ReflectionNamedType $type) => $type->getName(), $propertyType->getTypes());
                    sort($names);

                    // For numbers, pick the type that can contain the most value
                    if ($names === ['float', 'int'] || $names === ['float', 'int', 'null']) {
                        $propertyTypeName = 'float';
                    }
                } else {
                    $propertyTypeName = $propertyType->getName();
                }

                $gqlType = $typeMap[$propertyTypeName] ?? null;

                if ($gqlType) {
                    $types[$propertyName] = [
                        'name' => $propertyName,
                        'type' => $gqlType,
                    ];
                } else if ($propertyTypeName === 'array') {
                    $types[$propertyName] = [
                        'name' => $propertyName,
                        'type' => Type::string(),
                        'resolve' => function($field) use ($propertyName) {
                            $value = $field->{$propertyName};

                            return is_array($value) ? Json::encode($value) : $value;
                        },
                    ];
                }
            }
        }

        return $types;
    }

    public function getGqlTypeName(): string
    {
        $classNameParts = explode('\\', static::class);
        $end = array_pop($classNameParts);

        return 'Field_' . $end;
    }

    public function getExportLabel(ElementInterface $element): string
    {
        // Check to see if there's another field with the same label
        foreach ($element->getFields() as $field) {
            if ($field->id === $this->id) {
                continue;
            }

            if ($field->label === $this->label) {
                return $this->label . ' (' . $this->handle . ')';
            }
        }

        return $this->label;
    }

    public function getFullHandle()
    {
        $handles = [];

        // Get the namespace for each field, including parent fields
        $field = $this;

        while ($field) {
            // Be sure to prepend parent fields, as we're going deepest outward
            array_unshift($handles, $field->handle);

            $field = $field->getParentField();
        }

        return $handles;
    }

    public function getFullNamespace()
    {
        $names = [];

        // Get the namespace for each field, including parent fields
        $field = $this;

        while ($field) {
            // Be sure to prepend parent fields, as we're going deepest outward
            array_unshift($names, $field->getNamespace());

            $field = $field->getParentField();
        }

        return $names;
    }


    // Protected Methods
    // =========================================================================

    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['label', 'handle'], 'required'];

        $rules[] = [['placeholder', 'errorMessage', 'cssClasses'], 'string', 'max' => 255];

        $rules[] = [
            ['handle'],
            HandleValidator::class,
            'reservedWords' => self::_getReservedWords(),
        ];

        $rules[] = [
            ['handle'],
            UniqueValidator::class,
            'targetClass' => FieldRecord::class,
            'targetAttribute' => ['handle', 'layoutId'],
            'message' => Craft::t('yii', '{attribute} "{value}" has already been taken.'),
        ];

        // Only validate the ID if it’s not a new field
        if (!$this->getIsNew()) {
            $rules[] = [['id'], 'number', 'integerOnly' => true];
        }

        $rules[] = [
            ['labelPosition'],
            'in',
            'range' => Formie::$plugin->getFields()->getLabelPositions($this),
            'skipOnEmpty' => true,
        ];

        $rules[] = [
            ['instructionsPosition'],
            'in',
            'range' => Formie::$plugin->getFields()->getInstructionsPositions($this),
            'skipOnEmpty' => true,
        ];

        return $rules;
    }

    protected function cpInputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        if (method_exists($this, 'inputHtml')) {
            $html = $this->inputHtml($value, $element, $inline);

            // Tricky to deprecated this, but just flag it for people that are implementing `inputHtml()` still
            if ($html) {
                Craft::$app->getDeprecator()->log(__METHOD__, 'Formie fields’ `inputHtml()` method has been deprecated. Use `cpInputHtml()` instead.');

                return $html;
            }
        }

        return Html::textarea($this->handle, $value);
    }

    protected function requestParamName(ElementInterface $element): ?string
    {
        $namespace = $element->getFieldParamNamespace();

        return ($namespace ? $namespace . '.' : '') . $this->getFieldKey();
    }
    
    protected function setPrePopulatedValue(mixed $value): mixed
    {
        return $value;
    }

    protected function defineValueAsString(mixed $value, ElementInterface $element = null): string
    {
        // Escape any HTML in field content for good measure
        return StringHelper::cleanString((string)$value);
    }

    protected function defineValueAsJson(mixed $value, ElementInterface $element = null): mixed
    {
        return Json::decode(Json::encode($value));
    }

    protected function defineValueForExport(mixed $value, ElementInterface $element = null): mixed
    {
        // A string-representation will largely suit our needs
        return $this->defineValueAsString($value, $element);
    }

    protected function defineValueForIntegration(mixed $value, IntegrationField $integrationField, IntegrationInterface $integration, ElementInterface $element = null, string $fieldKey = ''): mixed
    {
        $fieldValue = $this->defineValueAsString($value, $element);

        // Special case for array fields, we should be using the `defineValueAsJson()` function
        if ($integrationField->getType() === IntegrationField::TYPE_ARRAY) {
            $fieldValue = $this->defineValueAsJson($value, $element);
        }

        return Integration::convertValueForIntegration($fieldValue, $integrationField);
    }

    protected function defineValueForSummary(mixed $value, ElementInterface $element = null): string
    {
        // A string-representation will largely suit our needs
        return $this->defineValueAsString($value, $element);
    }

    protected function defineValueForEmail(mixed $value, Notification $notification, ElementInterface $element = null): string
    {
        // A string-representation will largely suit our needs
        return $this->defineValueAsString($value, $element);
    }

    protected static function normalizeConfig(array &$config = []): void
    {
        // Normalise the config from Formie v1 to v2. This is a bit more reliable than a migration
        // updating all field settings, as the presence of these properties in field classes that don't
        // support them would be otherwise catastrophic, and blow up people's CP's.
        // Eventually, these can be removed at the next breakpoint, as users re-save their fields.
        if (array_key_exists('columnWidth', $config)) {
            unset($config['columnWidth']);
        }

        $supportedLimitConfigTypes = [
            formfields\MultiLineText::class,
            formfields\SingleLineText::class,
        ];

        $supportedLimitTypes = [
            formfields\Categories::class,
            formfields\Entries::class,
            formfields\FileUpload::class,
            formfields\MultiLineText::class,
            formfields\Number::class,
            formfields\Products::class,
            formfields\SingleLineText::class,
            formfields\Tags::class,
            formfields\Users::class,
            formfields\Variants::class,
        ];

        if (array_key_exists('limitType', $config)) {
            if (!in_array(static::class, $supportedLimitConfigTypes)) {
                unset($config['limitType']);
            }
        }

        if (array_key_exists('limitAmount', $config)) {
            if (!in_array(static::class, $supportedLimitConfigTypes)) {
                unset($config['limitAmount']);
            }
        }

        if (array_key_exists('limit', $config)) {
            if (!in_array(static::class, $supportedLimitTypes)) {
                unset($config['limit']);
            }
        }

        // Migrate field positions (particularly if importing from an older system)
        if (array_key_exists('instructionsPosition', $config)) {
            if ($config['instructionsPosition'] === 'verbb\\formie\\positions\\FieldsetStart') {
                $config['instructionsPosition'] = AboveInput::class;
            }

            if ($config['instructionsPosition'] === 'verbb\\formie\\positions\\FieldsetEnd') {
                $config['instructionsPosition'] = BelowInput::class;
            }
        }

        // Normalize config from v2 to v3. This is important to keep as long as possible, as field errors will be
        // triggered before migrations do.
        if (array_key_exists('subfieldLabelPosition', $config)) {
            $config['subFieldLabelPosition'] = ArrayHelper::remove($config, 'subfieldLabelPosition');
        }

        $removedProperties = [
            'isNested',
            'isSynced',

            // Relations
            'allowSelfRelations',
            'localizeRelations',
            'minRelations',
            'maxRelations',
            'selectionLabel',
            'showSiteMenu',
            'targetSiteId',
            'validateRelatedElements',
            'viewMode',
            'maintainHierarchy',
            'branchLimit',

            // Assets
            'restrictedLocationSource',
            'restrictedLocationSubpath',
            'allowSubfolders',
            'restrictedDefaultUploadSubpath',
            'defaultUploadLocationSource',
            'defaultUploadLocationSubpath',
            'allowUploads',
            'showUnpermittedVolumes',
            'showUnpermittedFiles',
            'previewMode',
            'showCardsInGrid',
            'useSingleFolder',
            'singleUploadLocationSource',
            'singleUploadLocationSubpath',

            // Categories
            'allowLimit',

            // Address
            'enableAutocomplete',

            // Misc
            'rowUid',
            'formId',
            'searchable',
            'translationMethod',
            'translationKeyFormat',
        ];

        foreach ($removedProperties as $removedProperty) {
            if (array_key_exists($removedProperty, $config)) {
                unset($config[$removedProperty]);
            }
        }
    }


    // Private Methods
    // =========================================================================

    private static function _getKebabName(): string
    {
        $classNameParts = explode('\\', static::class);
        $end = array_pop($classNameParts);

        return StringHelper::toKebabCase($end);
    }

    private static function _getReservedWords(): array
    {
        $reservedWords = [
            ['form', 'field', 'submission'],
        ];

        try {
            // Add public properties from submission class
            $reflection = new ReflectionClass(Submission::class);
            $reservedWords[] = array_map(function($prop) {
                return $prop->name;
            }, $reflection->getProperties(ReflectionProperty::IS_PUBLIC));

            // Add public properties from form class
            $reflection = new ReflectionClass(Form::class);
            $reservedWords[] = array_map(function($prop) {
                return $prop->name;
            }, $reflection->getProperties(ReflectionProperty::IS_PUBLIC));
        } catch (Throwable $e) {

        }

        return array_values(array_unique(array_merge(...$reservedWords)));
    }


    // Deprecated Methods
    // =========================================================================

    public function getName(): string
    {
        Craft::$app->getDeprecator()->log(__METHOD__, 'Formie fields’ `name` attribute has been deprecated. Use `label` instead.');

        return (string)$this->label;
    }

    public function setName(mixed $name): void
    {
        Craft::$app->getDeprecator()->log(__METHOD__, 'Formie fields’ `name` attribute has been deprecated. Use `label` instead.');
    }

}