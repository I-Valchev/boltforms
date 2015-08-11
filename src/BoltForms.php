<?php
namespace Bolt\Extension\Bolt\BoltForms;

use Bolt;
use Bolt\Application;
use Bolt\Extension\Bolt\BoltForms\Choice\ArrayType;
use Bolt\Extension\Bolt\BoltForms\Choice\ContentType;
use Bolt\Extension\Bolt\BoltForms\Config\FormConfig;
use Bolt\Extension\Bolt\BoltForms\Event\BoltFormsCustomDataEvent;
use Bolt\Extension\Bolt\BoltForms\Exception\FileUploadException;
use Bolt\Extension\Bolt\BoltForms\Exception\FormValidationException;
use Bolt\Extension\Bolt\BoltForms\Subscriber\BoltFormsSubscriber;
use Bolt\Helpers\Arr;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormTypeInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

/**
 * Core API functions for BoltForms
 *
 * Copyright (C) 2014-2015 Gawain Lynch
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Gawain Lynch <gawain.lynch@gmail.com>
 * @copyright Copyright (c) 2014, Gawain Lynch
 * @license   http://opensource.org/licenses/GPL-3.0 GNU Public License 3.0
 */
class BoltForms
{
    /** @var Application */
    private $app;
    /** @var array */
    private $config;
    /** @var array */
    private $forms;

    /**
     * @param Bolt\Application $app
     */
    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->config = $app[Extension::CONTAINER]->config;
    }

    /**
     * Get a particular form
     *
     * @param string $formname
     *
     * @return Form
     */
    public function getForm($formname)
    {
        return $this->forms[$formname];
    }

    /**
     * Initial form object constructor
     *
     * @param string                   $formname
     * @param string|FormTypeInterface $type
     * @param mixed                    $data
     * @param array                    $options
     */
    public function makeForm($formname, $type = 'form', $data = null, $options = array())
    {
        $options['csrf_protection'] = $this->config['csrf'];
        $this->forms[$formname] = $this->app['form.factory']->createNamedBuilder($formname, $type, $data, $options)
                                                            ->addEventSubscriber(new BoltFormsSubscriber($this->app))
                                                            ->getForm();
    }

    /**
     * Add a field to the form
     *
     * @param string $formname Name of the form
     * @param string $type
     * @param array  $options
     */
    public function addField($formname, $fieldname, $type, array $options)
    {
        if (isset($options['constraints'])) {
            if (gettype($options['constraints']) == 'string') {
                $options['constraints'] = $this->getConstraint($formname, $options['constraints']);
            } else {
                foreach ($options['constraints'] as $key => $constraint) {
                    $options['constraints'][$key] = $this->getConstraint($formname, array($key => $constraint));
                }
            }
        }

        if ($type === 'choice') {
            if (is_string($options['choices']) && strpos($options['choices'], 'contenttype::') === 0) {
                $choice = new ContentType($this->app, $fieldname, $options['choices']);
            } else {
                $choice = new ArrayType($fieldname, $options['choices']);
            }

            $options['choices'] = $choice->getChoices();
        }

        $this->forms[$formname]->add($fieldname, $type, $options);
    }

    /**
     * Add an array of fields to the form
     *
     * @param string $formname Name of the form
     * @param array  $fields   Associative array keyed on field name => array('type' => '', 'options => array())
     *
     * @return void
     */
    public function addFieldArray($formname, array $fields)
    {
        foreach ($fields as $fieldname => $field) {
            $field['options'] = empty($field['options']) ? array() : $field['options'];
            $this->addField($formname, $fieldname, $field['type'], $field['options']);
        }
    }

    /**
     * Extract, expand and set & create validator instance array(s)
     *
     * @param string $formname
     * @param mixed  $input
     *
     * @return Symfony\Component\Validator\Constraint
     */
    private function getConstraint($formname, $input)
    {
        $params = null;

        $namespace = "\\Symfony\\Component\\Validator\\Constraints\\";

        if (gettype($input) === 'string') {
            $class = $namespace . $input;
        } elseif (gettype($input) === 'array') {
            $input = current($input);
            if (gettype($input) === 'string') {
                $class = $namespace . $input;
            } elseif (gettype($input) === 'array') {
                $class = $namespace . key($input);
                $params = array_pop($input);
            }
        }

        if (class_exists($class)) {
            return new $class($params);
        }

        $this->app['logger.system']->error("[BoltForms] The form '$formname' has an invalid field constraint: '$class'.", array('event' => 'extensions'));
    }

    /**
     * Render our form into HTML
     *
     * @param string $formname   Name of the form
     * @param string $template   A Twig template file name in Twig's path
     * @param array  $twigvalues Associative array of key/value pairs to pass to Twig's render of $template
     *
     * @return \Twig_Markup
     */
    public function renderForm($formname, $template = '', array $twigvalues = array())
    {
        if (empty($template)) {
            $template = $this->config['templates']['form'];
        }

        // Add the form object for use in the template
        $renderdata = array('form' => $this->forms[$formname]->createView());

        // Add out passed values to the array to be given to render()
        foreach ($twigvalues as $twigname => $data) {
            $renderdata[$twigname] = $data;
        }

        //
        $this->app['twig.loader.filesystem']->addPath(dirname(__DIR__) . '/assets');

        // Pray and do the render
        $html = $this->app['render']->render($template, $renderdata);

        // Return the result
        return new \Twig_Markup($html, 'UTF-8');
    }
}
