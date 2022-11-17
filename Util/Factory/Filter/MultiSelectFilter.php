<?php

namespace Ali\DatatableBundle\Util\Factory\Filter;

/**
 * Class MultiSelectFilter
 *
 * basically identical to DatatableFilter, but will accept comma seperated values and
 * search for all values, see DoctrineBuilder.php
 *
 * @author Rein Baarsma <rein@solidwebcode.com>
 */
class MultiSelectFilter extends DatatableFilter
{
    protected $search_field;

    /**
     * MultiSelectFilter constructor.
     * @param array $filter_values
     * @param string $search_type
     * @param $search_field
     */
    public function __construct(string $search_field, array $entities, $label_getter = '__toString', $value_getter = 'getId')
    {
        $filters = [];
        foreach ($entities as $entity)
        {
            $value = $entity->{$value_getter}();
            $label = $entity->{$label_getter}();
            $filters[] = new DatatableFilterValue($value, $label);
        }

        parent::__construct($filters, self::SEARCH_TYPE_EQUALS);
        $this->search_field = $search_field;
    }

    /**
     * @return mixed
     */
    public function getSearchField()
    {
        return $this->search_field;
    }
}