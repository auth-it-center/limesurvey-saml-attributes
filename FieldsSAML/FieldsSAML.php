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
        $this->registerField('full-name', 'cn', false);
        $this->registerField('first-name', 'givenName', false);
        $this->registerField('last-name', 'sn', false);
        $this->registerField('email', 'mail', false);
        $this->registerField('department', 'authDepartmentId', false);
        $this->registerField('affiliation', 'eduPersonPrimaryAffiliation', false, 'select');
        $this->appendToSettings('affiliation_array', [
            'type' => 'string',
            'label' => 'Available affiliations',
            'help' => 'Comma separated, without spaces',
            'default' => 'faculty,student,staff,affiliate,employee',
        ], function ($value) {
            $default = 'faculty,student,staff,affiliate,employee';
            $affiliations = $this->get('affiliation_array', null, null, $default);
            $array = explode(',', $affiliations);
            foreach ($array as $index => $string) {
                if ($value == $string) {
                    return 'A' . ($index + 1);
                }
            }
        });
        $this->registerField('title', 'title', false);
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
        $this->subscribe('beforeSurveyPage');
        $this->subscribe('afterSurveyComplete');
    }

    public function registerField($name, $attributeName, $enabledDefault = false, $templateInput = 'input', $parser = false)
    {
        $this->appendToSettings($name . '_mapping', [
            'type' => 'string',
            'label' => "SAML attribute used as $name",
            'default' => $attributeName
        ]);
        $this->appendToSettings('fields_' . $name . '_enabled', [
            'type' => 'checkbox',
            'label' => "Enable $name field",
            'help' => "Enable the extraction of $name attribute to the survey",
            'default' => $enabledDefault
        ]);
        $this->appendToHTMLTemplates($name, function ($value) {
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
        $event = $this->event;

        $event->set('surveysettings.' . $this->id, [
            'name' => get_class($this),
            'settings' => $this->prepareDefaultSurveySettings($event)
        ]);
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
            $default = $event->get($name, null, null, isset($this->settings[$name]['default']));
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

        array_walk($this->htmlTemplates, function ($templateFunction, $key) {
            if ($filter[$key]) {
                $script .= $templateFunction($attributes[key]);
            }
        });

        $script .= "
            }
        </script>
        ";

        if ($plugin_enabled) {
            echo $script;
        } else {
            echo "
            <script>
            function setPersonalData() {}
            </script>
            ";
        }
    }

    public function getAttributesSAML()
    {
        $AuthSAML = $this->pluginManager->loadPlugin('AuthSAML');

        $ssp = $AuthSAML->get_saml_instance();

        $ssp->requireAuth();

        $attributes = $ssp->getAttributes();

        return $this->array_map(function ($name, $value) use ($attributes) {
            $fieldName = $this->get($name . '_mapping', null, null);
            $value = $this->extractTranslatedAttribute($attributes, $fieldName);
            $parser = $this->$fieldParsers[$name];
            if ($parser !== false) {
                $value = $parser($value);
            }
            return $value;
        }, $this->htmlTemplates);

        $attributes = [
            'affiliation' => $this->mapAffiliation($this->extractTranslatedAttribute($attributes, $affiliationField)),
        ];

        return $attributes;
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
        return $attributes[$field][$index];
    }

    public function getAttributesFieldMap($survey)
    {
        $fieldMap = createFieldMap($survey, 'full', null, false, $response->attributes['startlanguage']);
        $fieldKeys = array_keys($this->htmlTemplates);
        $fieldMap = array_filter($fieldMap, function($item) {
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
