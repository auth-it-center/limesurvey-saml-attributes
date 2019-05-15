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
        'name_mapping' => [
            'type' => 'string',
            'label' => 'SAML attribute used as name',
            'default' => 'cn',
        ],
        'email_mapping' => [
            'type' => 'string',
            'label' => 'SAML attribute used as email',
            'default' => 'mail',
        ],
        'department_mapping' => [
            'type' => 'string',
            'label' => 'SAML attribute used as department',
            'default' => 'authDepartmentId',
        ],
        'affiliation_mapping' => [
            'type' => 'string',
            'label' => 'SAML attribute used as affiliation',
            'default' => 'eduPersonPrimaryAffiliation',
        ],
        'affiliation_array' => [
            'type' => 'string',
            'label' => 'Available affiliations',
            'help' => 'comma seperated, without spaces',
            'default' => 'faculty,student,staff,affiliate,employee',
        ],
        'title_mapping' => [
            'type' => 'string',
            'label' => 'SAML attribute used as title',
            'default' => 'title',
        ],
        'language_index' => [
            'type' => 'string',
            'label' => 'Language Index',
            'help' => 'If SAML provider support multiple languages, choose the desired language index',
            'default' => '0'
        ]
    ];

    public function init()
    {
        $this->subscribe('beforeSurveySettings');
        $this->subscribe('newSurveySettings');
        $this->subscribe('beforeSurveyPage');
        $this->subscribe('afterSurveyComplete');
    }

    public function beforeSurveySettings()
    {
        $event = $this->event;

        $event->set('surveysettings.' . $this->id, [
            'name' => get_class($this),
            'settings' => [
                'fields_SAML_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Enabled',
                    'help' => 'Enable the extraction of SAML attributes to the survey',
                    'default' => false,
                    'current' => $this->get('fields_SAML_enabled', 'Survey', $event->get('survey')),
                ],
                'fields_email_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Enable email field',
                    'help' => 'Enable the extraction of email attribute to the survey',
                    'default' => false,
                    'current' => $this->get('fields_email_enabled', 'Survey', $event->get('survey')),
                ],
                'fields_name_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Enable name field',
                    'help' => 'Enable the extraction of name attribute to the survey',
                    'default' => false,
                    'current' => $this->get('fields_name_enabled', 'Survey', $event->get('survey')),
                ],
                'fields_department_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Enabled department field',
                    'help' => 'Enable the extraction of department attribute to the survey',
                    'default' => false,
                    'current' => $this->get('fields_department_enabled', 'Survey', $event->get('survey')),
                ],
                'fields_affiliation_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Enable affiliation field',
                    'help' => 'Enable the extraction of affiliation attribute to the survey',
                    'default' => false,
                    'current' => $this->get('fields_affiliation_enabled', 'Survey', $event->get('survey')),
                ],
                'fields_title_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Enable title field',
                    'help' => 'Enable the extraction of title attribute to the survey',
                    'default' => false,
                    'current' => $this->get('fields_title_enabled', 'Survey', $event->get('survey')),
                ]
            ]
        ]);
    }

    public function newSurveySettings()
    {
        $event = $this->event;
        foreach ($event->get('settings') as $name => $value)
        {
            $default = $event->get($name, null, null, isset($this->settings[$name]['default']));
            $this->set($name, $value, 'Survey', $event->get('survey'), $default);
        }
    }

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

        if ($filter['email']) {
            $script .= "
                let emailField = document.querySelector('.saml-email input')
                if (emailField) {
                    emailField.value = '{$attributes['email']}'
                    emailField.disabled = true
                }
            ";
        }

        if ($filter['name']) {
            $script .= "
                let nameField = document.querySelector('.saml-name input')
                if (nameField) {
                    nameField.value = '{$attributes['name']}'
                    nameField.disabled = true
                }
            ";
        }

        if ($filter['affiliation']) {
            $script .= "
                let affiliationField = document.querySelector('.saml-affiliation select')
                if (affiliationField) {
                    affiliationField.value = '{$attributes['affiliation']}'
                    affiliationField.disabled = true
                }
            ";
        }

        if ($filter['title']) {
            $script .= "
                let titleField = document.querySelector('.saml-title input')
                if (titleField) {
                    titleField.value = '{$attributes['title']}'
                    titleField.disabled = true
                }
            ";
        }

        if ($filter['department']) {
            $script .= "
                let departmentField = document.querySelector('.saml-department input')
                if (departmentField) {
                    departmentField.value = '{$attributes['department']}'
                    departmentField.disabled = true
                }
            ";
        }

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

    public function mapAffiliation($affiliation)
    {
        $array = 'faculty,student,staff,affiliate,employee';
        $array = $this->get('affiliation_array', null, null, $array);
        $array = explode(',', $array);
        foreach ($array as $index => $string) {
            if ($affiliation == $string) {
                return 'A' . ($index + 1);
            }
        }
    }

    public function getAttributesSAML()
    {
        $AuthSAML = $this->pluginManager->loadPlugin('AuthSAML');

        $ssp = $AuthSAML->get_saml_instance();

        $ssp->requireAuth();

        $attributes = $ssp->getAttributes();

        $nameField = $this->get('name_mapping', null, null, 'cn');
        $emailField = $this->get('email_mapping', null, null, 'mail');
        $department = $this->get('department_mapping', null, null, 'authDepartmentId');
        $affiliationField = $this->get('affiliation_mapping', null, null, 'eduPersonPrimaryAffiliation');
        $titleField = $this->get('title_mapping', null, null, 'title');

        $attributes = [
            'name' => $this->extractFromAttributes($attributes, $nameField),
            'email' => $this->extractFromAttributes($attributes, $emailField),
            'department' => $this->extractFromAttributes($attributes, $department),
            'title' => $this->extractFromAttributes($attributes, $titleField),
            'affiliation' => $this->mapAffiliation($this->extractFromAttributes($attributes, $affiliationField)),
        ];


        return $attributes;
    }

    private function extractFromAttributes($attributes, $field) {
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
        $fieldmap = createFieldMap($survey, 'full', null, false, $response->attributes['startlanguage']);
        $fieldmap = array_filter($fieldmap, function($item) {
            if (in_array($item['title'], ['email', 'name', 'department', 'affiliation', 'title'])) {
                return true;
            }
            return false;
        });
        return $fieldmap;
    }

    public function getAttributesFilter()
    {
        $id = $this->getEvent()->get('surveyId');
        return [
            'email' => $this->get('fields_email_enabled', 'Survey', $id),
            'name' => $this->get('fields_name_enabled', 'Survey', $id),
            'department' => $this->get('fields_department_enabled', 'Survey', $id),
            'affiliation' => $this->get('fields_affiliation_enabled', 'Survey', $id),
            'title' => $this->get('fields_title_enabled', 'Survey', $id),
        ];
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
        $fieldmap = $this->getAttributesFieldMap($survey);
        $attributes = $this->getAttributesSAML();
        $attributes = $this->filterEnabledAttributes($attributes);
        $response = [];
        array_walk($fieldmap, function($item, $key) use (&$response, $attributes) {
            $title = $item['title'];
            if (array_key_exists($title, $attributes)) {
                $response[$key] = $attributes[$title];
            }
        });
        return $response;
    }

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
}
