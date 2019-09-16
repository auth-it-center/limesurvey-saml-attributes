<?php

/**
 * LimeSurvey FieldsSAML
 *
 * This plugin forces selected surveys to
 * extract SAML attributes and append them in the survey
 *
 * WARNING: IT CAN BE USED TO COLLECT USER DATA, USE WITH CAUTIOUS
 *
 * Author: Panagiotis Karatakis <karatakis@it.auth.gr>
 * Licence: GPL3
 *
 * Sources:
 * https://manual.limesurvey.org/Plugins_-_advanced
 * https://manual.limesurvey.org/Plugin_events
 * https://medium.com/@evently/creating-limesurvey-plugins-adcdf8d7e334
 */

class FieldsSAML extends Limesurvey\PluginManager\PluginBase
{
    protected $storage = 'DbStorage';
    static protected $description = 'This plugin forces selected surveys to extract SAML attributes and append them in the survey';
    static protected $name = 'FieldsSAML';

    protected $settings = [
        'language_index' => [
            'type' => 'string',
            'label' => 'Language Index',
            'help' => 'If SAML provider support multiple languages, choose the desired language index',
            'default' => '0'
        ]
    ];

    protected $defaultSurveySettings = [
        'fields_SAML_enabled' => [
            'type' => 'checkbox',
            'label' => 'Enabled',
            'help' => 'Enable the extraction of SAML attributes to the survey',
            'default' => false
        ]
    ];

    protected $htmlTemplates = [];

    protected $fieldParsers = [];

    public function init()
    {
        $this->registerField('fullName', 'cn', false);
        $this->registerField('firstName', 'givenName', false);
        $this->registerField('lastName', 'sn', false);
        $this->registerField('email', 'mail', false);
        $this->registerField('department', 'authDepartmentId', false);
        $this->registerField('affiliation', 'eduPersonPrimaryAffiliation', false);
        $this->registerField('title', 'title', false);
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
        $this->subscribe('beforeSurveyPage');
        $this->subscribe('afterSurveyComplete');
        $this->subscribe('getGlobalBasePermissions');
    }

    public function getGlobalBasePermissions() {
        $this->getEvent()->append('globalBasePermissions',array(
            'plugin_settings' => array(
                'create' => false,
                'update' => true, // allow only update permission to display
                'delete' => false,
                'import' => false,
                'export' => false,
                'read' => false,
                'title' => gT("Save Plugin Settings"),
                'description' => gT("Allow user to save plugin settings"),
                'img' => 'usergroup'
            ),
        ));
    }

    public function registerField($name, $attributeName, $enabledDefault = false, $templateInput = 'input', $parser = false)
    {
        $this->appendToSettings($name . '_mapping', [
            'type' => 'string',
            'label' => "SAML attribute used as $name",
            'default' => $attributeName
        ]);
        $this->appendToDefaultSurveySettings('fields_' . $name . '_enabled', [
            'type' => 'checkbox',
            'label' => "Enable $name field",
            'help' => "Enable the extraction of $name attribute to the survey",
            'default' => $enabledDefault
        ]);
        $this->appendToHTMLTemplates($name, function ($value) use ($name, $templateInput) {
            return "
                let {$name}Field = document.querySelector('.saml-{$name} {$templateInput}')
                if ({$name}Field) {
                    {$name}Field.value = '{$value}'
                    {$name}Field.disabled = true
                }
            ";
        });
        $this->appendToParsers($name, $parser);
    }

    public function appendToSettings($key, $setting)
    {
        $this->settings[$key] = $setting;
    }

    public function appendToDefaultSurveySettings($key, $setting)
    {
        $this->defaultSurveySettings[$key] = $setting;
    }

    public function appendToHTMLTemplates($key, $function)
    {
        $this->htmlTemplates[$key] = $function;
    }

    /**
     * appendToParsers::k -> M f -> _
     */
    public function appendToParsers($key, $function)
    {
        $this->fieldParsers[$key] = $function;
    }

    public function prepareDefaultSurveySettings($event)
    {
        return $this->array_map(function($key, $setting) use ($event) {
            if (strpos($key, 'fields_') === 0) {
                $setting['current'] = $this->get($key, 'Survey', $event->get('survey'));
            }
            return $setting;
        }, $this->defaultSurveySettings);
    }

    /**
     * beforeSurveySettings hook
     */
    public function beforeSurveySettings()
    {
        $permission = Permission::model()->hasGlobalPermission('plugin_settings', 'update');
        if ($permission) {
            $event = $this->event;

            $event->set('surveysettings.' . $this->id, [
                'name' => get_class($this),
                'settings' => $this->prepareDefaultSurveySettings($event)
            ]);
        }
    }

    /**
     * newSurveySettings hook
     * used to save custom survey settings or use the default value
     */
    public function newSurveySettings()
    {
        $event = $this->event;
        foreach ($event->get('settings') as $name => $value)
        {
            $default = $event->get($name, null, null, $this->settings[$name]['default']);
            $this->set($name, $value, 'Survey', $event->get('survey'), $default);
        }
    }

    /**
     * beforeSurveyPage hook
     */
    public function beforeSurveyPage()
    {
        $plugin_enabled = $this->get('fields_SAML_enabled', 'Survey', $this->event->get('surveyId'));

        if (! $plugin_enabled) {
            echo "
            <script>
            function setPersonalData() {}
            </script>
            ";
            return false;
        }

        $attributes = $this->getAttributesSAML();
        $filter = $this->getAttributesFilter();

        $script = '';
        $script = "
        <script>
            function setPersonalData() {
                console.log('Personal Data Set')
        ";
        array_walk($this->htmlTemplates, function ($templateFunction, $key) use (&$script, $filter, $attributes) {
            if ($filter[$key]) {
                $script = $script . $templateFunction($attributes[$key]);
            }
        });

        $script .= "
            }
        </script>
        ";

        echo $script;
    }

    public function getAttributesSAML()
    {
        $AuthSAML = $this->pluginManager->loadPlugin('AuthSAML');

        $ssp = $AuthSAML->get_saml_instance();

        $ssp->requireAuth();

        $attributes = $ssp->getAttributes();

        // Apply parsers
        return $this->array_map(function ($name, $value) use ($attributes) {
            $mappingName = $name . '_mapping';
            $fieldName = $this->get($mappingName, null, null, $this->settings[$mappingName]['default']);
            $value = $this->extractTranslatedAttribute($attributes, $fieldName);
            $parser = $this->fieldParsers[$name];
            if ($parser !== false) {
                $value = $parser($value);
            }
            return $value;
        }, $this->htmlTemplates);
    }

    private function extractTranslatedAttribute($attributes, $field)
    {
        $index = 0;
        // if SAML attribute is multilingual, set the index to language_index
        if (isset($attributes[$field]) && count($attributes[$field]) > 1) {
            $index = $this->get('language_index', null, null, '0');
            // check if $index is a valid number
            if (is_numeric($index)) {
                // if it is parse it into int
                $index = (int) $index;
            } else {
                // if not default it to 0
                $index = 0;
            }
        }
        if (isset($attributes[$field][$index])) {
            return $attributes[$field][$index];
        }
        return "Attribute $field is missing. Please report this incident at the administrators.";
    }

    public function getAttributesFieldMap($survey)
    {
        $fieldMap = createFieldMap($survey, 'full', null, false, $response->attributes['startlanguage']);
        $fieldKeys = array_keys($this->htmlTemplates);
        $fieldMap = array_filter($fieldMap, function($item) use ($fieldKeys) {
            if (in_array($item['title'], $fieldKeys)) {
                return true;
            }
            return false;
        });
        return $fieldMap;
    }

    public function getAttributesFilter()
    {
        $id = $this->getEvent()->get('surveyId');
        return $this->array_map(function ($name, $value) use ($id) {
            return $this->get("fields_{$name}_enabled", 'Survey', $id);
        }, $this->htmlTemplates);
    }

    public function filterEnabledAttributes($attributes)
    {
        $filter = $this->getAttributesFilter();
        return array_filter($attributes, function($key) use ($filter) {
            return $filter[$key];
        }, ARRAY_FILTER_USE_KEY);
    }

    public function getSAMLResponse($survey)
    {
        $fieldMap = $this->getAttributesFieldMap($survey);
        $attributes = $this->getAttributesSAML();
        $attributes = $this->filterEnabledAttributes($attributes);
        $response = [];
        array_walk($fieldMap, function($item, $key) use (&$response, $attributes) {
            $title = $item['title'];
            if (array_key_exists($title, $attributes)) {
                $response[$key] = $attributes[$title];
            }
        });
        return $response;
    }

    /**
     * afterSurveyComplete hook
     */
    public function afterSurveyComplete()
    {
        $event = $this->getEvent();
        $surveyId = $event->get('surveyId');
        $responseId = $event->get('responseId');
        $survey = \Survey::model()->findByPk($surveyId);

        $plugin_enabled = $this->get('fields_SAML_enabled', 'Survey', $surveyId);

        if ($plugin_enabled) {
            $response = $this->getSAMLResponse($survey);
            if (count($response) > 0) {
                $status = \SurveyDynamic::model($surveyId)->updateByPk($responseId, $response);
            }
        }
    }

    /**
     * array_map::(k -> a -> b) -> [(k, a)] -> [(k, b)]
     */
    private function array_map($fn, $array) {
        $keys = array_keys($array);
        $valuesMapped = array_map($fn, $keys, $array);
        return array_combine($keys, $valuesMapped);
    }
}
