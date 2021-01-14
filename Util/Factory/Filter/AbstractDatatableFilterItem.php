<?php

namespace Util\Factory\Filter;

use Ali\DatatableBundle\Util\Exceptions\InvalidFilterValueLabelException;

/**
 * Class AbstractDatatableFilterItem
 * @author Maarten Sprakel <maarten.sprakel@extendas.com>
 */
class AbstractDatatableFilterItem implements DatatableFilterItemInterface
{
    protected $value;

    /** @var string */
    protected $label;

    public function __construct($value, $label)
    {
        if (!is_string($label))
        {
            throw new InvalidFilterValueLabelException($label);
        }
        $this->label = $label;
        $this->value = $value;
    }

    /**
     * @return mixed
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * @param mixed $value
     * @return DatatableFilterItemInterface
     */
    public function setValue($value)
    {
        $this->value = $value;
        return $this;
    }

    /**
     * @return string
     */
    public function getLabel()
    {
        return $this->label;
    }

    /**
     * @param string $label
     * @return DatatableFilterItemInterface
     */
    public function setLabel($label)
    {
        $this->label = $label;
        return $this;
    }
}