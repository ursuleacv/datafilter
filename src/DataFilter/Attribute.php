<?php


/*
 * This file is part of DataFilter.
 *
 * (c) Ulrich Kautz <ulrich.kautz@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DataFilter;

use \DataFilter\Util as U;

/**
 * Data attribute
 *
 * Attributes are named input parameters with validation rules and filters
 *
 * @author Ulrich Kautz <ulrich.kautz@gmail.com>
 */

class Attribute
{
    use \DataFilter\Traits\Filter;

    /**
     * @var array
     */
    protected static $DEFAULT_ATTRIBS = [
        'required'       => false,
        'matchAny'       => false,
        'default'        => null,
        'missing'        => null,
        'rules'          => [],
        'dependent'      => [],
        'dependentRegex' => [],
        'preFilters'     => [],
        'postFilters'    => [],
    ];

    /**
     * @var \DataFilter\Profile
     */
    protected $dataFilter;

    /**
     * @var string
     */
    protected $name;

    /**
     * @var bool
     */
    protected $required = false;

    /**
     * @var bool
     */
    protected $matchAny = false;

    /**
     * @var string
     */
    public $default = null;

    /**
     * @var string
     */
    public $missing = null;

    /**
     * @var array
     */
    protected $rules;

    /**
     * @var array
     */
    protected $dependent;

    /**
     * @var array
     */
    protected $dependentRegex;

    /**
     * @var \DataFilter\Rule
     */
    protected $failedRule;

    /**
     * @var string
     */
    protected $lastValue;

    /**
     * Constructor for DataFilter\Attribute
     *
     * @param string                $name        Name of the attrib (unique per data filter)
     * @param mixed                 $definition  The defnition (containing rule and stuff)
     * @param \DataFilter\Profile   $dataFilter  Parental data filter
     */
    public function __construct($name, $definition, \DataFilter\Profile &$dataFilter)
    {
        $this->name = $name;
        $this->dataFilter = $dataFilter;
        $this->rules = [];
        $this->dependent = [];
        $this->dependentRegex = [];
        $this->preFilters = [];
        $this->postFilters = [];

        // required, simple
        if ($definition === true) {
            $this->required = true;
        }

        // optional, simple
        elseif ($definition === false) {
            $this->required = false;
        }

        // complex..
        else {

            // from string or callable (simple, optioanl)
            if (is_string($definition) || is_callable($definition)) {
                $definition = ['rules' => ['default' => $definition]];
            }

            // init empty to reduce isset checks..
            $definition = array_merge(self::$DEFAULT_ATTRIBS, $definition);

            // set attribs
            foreach (['required', 'matchAny', 'default', 'dependent', 'dependentRegex', 'missing'] as $k) {
                $this->$k = $definition[$k];
            }

            // add all rules
            $this->setRules($definition['rules']);

            // add all filter
            $this->addFilters('pre', $definition['preFilters']);
            $this->addFilters('post', $definition['postFilters']);
        }
    }

    /**
     * Set (replace/add) multiple rules at once
     *
     * @param array  $rules  List of rules
     */
    public function setRules($rules)
    {
        foreach ($rules as $ruleName => $rule) {
            $this->setRule($ruleName, $rule);
        }
    }

    /**
     * Set (replace/add) a single named rule
     *
     * @param string  $ruleName  Name of the rule (unique per attribute)
     * @param mixed   $rule      the rule definition or a \DataFilter\Rule object
     *
     * @return bool
     */
    public function setRule($ruleName, $definition)
    {
        $this->rules[$ruleName] = is_object($definition) && $definition instanceof \DataFilter\Rule
            ? $definition
            : new \DataFilter\Rule($ruleName, $definition, $this, $this->dataFilter);
    }

    /**
     * List of all rules as array of (name => \DataFilter\Rule)
     *
     * @return array
     */
    public function getRules()
    {
        return $this->rules;
    }

    /**
     * Get a single rule by name (or null)
     *
     * @param string  $ruleName  Name of the rule (unique per attribute)
     *
     * @return \DataFilter\Rule
     */
    public function getRule($ruleName)
    {
        return isset($this->rules[$ruleName]) ? $this->rules[$ruleName] : null;
    }

    /**
     * Removes a single rule by name
     *
     * @param string  $ruleName  Name of the rule (unique per attribute)
     *
     * @return bool  Whether removed
     */
    public function removeRule($ruleName)
    {
        if (isset($this->rules[$ruleName])) {
            unset($this->rules[$ruleName]);
            return true;
        }
        return false;
    }

    /**
     * Check all rules of this attribyte against input
     *
     * @param string  $input  Input data
     *
     * @return bool
     */
    public function check($input)
    {
        $this->lastValue = $input;
        $anyFailed = false;
        foreach ($this->rules as $ruleName => &$rule) {

            // at least OK
            if ($rule->check($input)) {

                // stop here if any is OK or rule is sufficient
                if ($this->matchAny || $rule->isSufficient()) {
                    return true;
                }
            }

            // if not in match any mode -> first fail stops
            elseif (!$this->matchAny) {
                $this->failedRule = &$rule;
                return false;
            }

            // at least one failed
            else {
                if (!$anyFailed) {
                    $this->failedRule = &$rule;
                }
                $anyFailed = true;
            }
        }

        // all have to work out!
        return !$anyFailed;
    }

    /**
     * Adds possible requireds to list if. Only if not have error.
     *
     * @param array  $required
     */
    public function determineDependents($input, array &$required)
    {
        if ($this->hasError()) {
            return;
        }
        $this->lastValue = $input;

        // check all simple dependents
        $foundRequired = false;
        foreach ($this->dependent as $onInput => $requiredNames) {
            if ($onInput === '*') {
                continue;
            }
            elseif ($input === $onInput) {
                $foundRequired = true;
                foreach ($requiredNames as $attribName) {
                    $required[$attribName] = true;
                }
            }
        }

        // the default dependent does apply if no simple were found and input is given (not empty)
        if ($input && !$foundRequired && isset($this->dependent['*'])) {
            foreach ($this->dependent['*'] as $attribName) {
                $required[$attribName] = true;
            }
        }

        // apply regex dependent
        foreach ($this->dependentRegex as $onRegex => $requiredNames) {
            if (preg_match($onRegex, $input)) {
                foreach ($requiredNames as $attribName) {
                    $required[$attribName] = true;
                }
            }
        }
    }

    /**
     * Returns attribute name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Returns last input value used for check (or determine Dependent)
     *
     * @return string
     */
    public function getLastValue()
    {
        return $this->lastValue;
    }

    /**
     * Returns error
     *
     * @return string
     */
    public function getError()
    {
        if ($this->failedRule) {
            return $this->failedRule->getError($this);
        }
        return null;
    }

    /**
     * Whether any has failed
     *
     * @return bool
     */
    public function hasError()
    {
        return $this->failedRule ? true : false;
    }

    /**
     * Returns formatted missing text
     *
     * @return string
     */
    public function getMissingText()
    {
        $missing = $this->missing ?: $this->dataFilter->getMissingTemplate();
        return U::formatString($missing, [
            'attrib' => $this->name
        ]);
    }

    /**
     * Returns default value (or null)
     *
     * @return string
     */
    public function getDefault()
    {
        return $this->default;
    }

    /**
     * Returns whether required
     *
     * @return bool
     */
    public function isRequired()
    {
        return $this->required;
    }

    /**
     * Sets required mode
     *
     * @param bool  $mode  The mode. Defaults to true
     */
    public function setRequired($mode = true)
    {
        $this->required = $mode;
    }

    /**
     * Sets matchAny mode
     *
     * @param bool  $mode  The state. Defaults to true
     */
    public function setMatchAny($mode = true)
    {
        $this->matchAny = $mode;
    }

    /**
     * Sets default string (or null)
     *
     * @param string  $default  Defaults to null
     */
    public function setDefault($default = null)
    {
        $this->default = $default;
    }

    /**
     * Sets missing template (or null)
     *
     * @param string  $template  Defaults to null
     */
    public function setMissing($template = null)
    {
        $this->missing = $template;
    }

    /**
     * Resets check results
     */
    public function reset()
    {
        $this->failedRule = null;
    }

}
