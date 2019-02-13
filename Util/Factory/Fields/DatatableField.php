<?php

namespace Ali\DatatableBundle\Util\Factory\Fields;

/**
 * The default datatable field to be used in the Datatable->setFields method.
 *
 * Class DatatableField
 * @package Ali\DatatableBundle\Util\Factory\Fields
 */
class DatatableField
{
    /** @var string */
    protected $field;

    /** @var boolean */
    protected $search;

    /** @var string */
    protected $renderer;

    public function __construct($field, $search = null, $renderer = null)
    {
        $this->field = $field;
        $this->search = $search;
        $this->renderer = $renderer;
    }

    public function __toString()
    {
        return $this->field;
    }

    /**
     * @return string
     */
    public function getField()
    {
        return $this->field;
    }

    /**
     * @param string $field
     * @return DatatableField
     */
    public function setField($field)
    {
        $this->field = $field;

        return $this;
    }

    /**
     * @return bool
     */
    public function isSearch(): bool
    {
        return $this->search;
    }

    /**
     * @param bool $search
     *
     * @return DatatableField
     */
    public function setSearch($search)
    {
        $this->search = $search;
        return $this;
    }

    /**
     * @return string
     */
    public function getRenderer()
    {
        return $this->renderer;
    }

    /**
     * @param string $renderer
     *
     * @return DatatableField
     */
    public function setRenderer($renderer)
    {
        $this->renderer = $renderer;
        return $this;
    }
}