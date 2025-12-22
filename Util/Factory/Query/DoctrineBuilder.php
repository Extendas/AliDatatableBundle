<?php

namespace Ali\DatatableBundle\Util\Factory\Query;

use Ali\DatatableBundle\Util\Datatable;
use Ali\DatatableBundle\Util\Exceptions\CustomJoinFieldException;
use Ali\DatatableBundle\Util\Factory\Fields\DatatableField;
use Ali\DatatableBundle\Util\Factory\Fields\EntityDatatableField;
use Ali\DatatableBundle\Util\Factory\Filter\DatatableFilter;
use Ali\DatatableBundle\Util\Factory\Filter\DateTimeFilter;
use Ali\DatatableBundle\Util\Factory\Filter\MultiSelectFilter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use ShipMonk\Doctrine\MySql\IndexHint;
use ShipMonk\Doctrine\MySql\UseIndexHintHandler;
use ShipMonk\Doctrine\Walker\HintDrivenSqlWalker;
use Symfony\Component\HttpFoundation\Request;
use Ali\DatatableBundle\Util\Factory\Fields\DQLDatatableField;

class DoctrineBuilder implements QueryInterface
{
    private const SEARCH_MODE_EQUALS = 'equals';
    private const SEARCH_MODE_LIKE = 'like';
    private const SEARCH_MODE_OR_LIKE = 'or_like';
    private const SEARCH_MODE_IN = 'in';
    private const SEARCH_MODE_BETWEEN = 'between';

    /** @var \Doctrine\ORM\EntityManager */
    protected $em;

    /** @var \Symfony\Component\HttpFoundation\Request */
    protected $request;

    /** @var \Doctrine\ORM\QueryBuilder */
    protected $queryBuilder;

    /** @var string */
    protected $entity_name;

    /** @var string */
    protected $entity_alias;

    /** @var DatatableField[]|array */
    protected $fields;

    /** @var string */
    protected $order_field = NULL;

    /** @var string */
    protected $order_type = "asc";

    /** @var string */
    protected $where = NULL;

    /** @var array */
    protected $joins = array();

    /** @var boolean */
    protected $has_action = true;

    /** @var array */
    protected $fixed_data = NULL;

    /** @var closure */
    protected $renderer = NULL;

    /** @var boolean */
    protected $search = FALSE;

    /** @var array */
    protected $query_hints;

    /** @var IndexHint[] */
    protected array $forced_indexes = [];

    /** @var string|null */
    private $_lowest_entity_field_id;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em           = $em;
        $this->request      = Request::createFromGlobals();
        $this->queryBuilder = $this->em->createQueryBuilder();
        $this->query_hints  = [];
    }

    /**
     * get the search dql
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     * @param array $filter_fields
     * @throws \Exception
     */
    protected function _addSearch(\Doctrine\ORM\QueryBuilder $queryBuilder, array $filter_fields=[])
    {
        if ($this->search == TRUE)
        {
            $request       = $this->request;
            $search_fields = array_values($this->fields);
            foreach ($search_fields as $i => $search_field)
            {
                $search_param = $request->get("sSearch_{$i}");

                $filter = $filter_fields[$i] ?? null;
                $is_required_date_filter = $filter instanceof DateTimeFilter && $filter->isRequired();
                if (!$search_field instanceof DatatableField)
                {
                    $search_field = new DatatableField($search_field);
                }
                if ($search_param !== false && $search_param != '' || $is_required_date_filter)
                {
                    $this->addSearchForColumn($queryBuilder, $search_param, $i, $search_field, $filter);
                }
            }
        }
    }

    public function addSearchForColumn(\Doctrine\ORM\QueryBuilder $queryBuilder, ?string $search_value, int $index, DatatableField $field, ?DatatableFilter $filter)
    {
        $search_field = $field->getField();
        $search_mode = self::SEARCH_MODE_LIKE;
        $search_type = 'andWhere';
        if ($field instanceof DQLDatatableField && $field->getNeedsHaving())
        {
            $search_type = 'andHaving';
        }
        $entity_field_alias = null;
        if ($field instanceof EntityDatatableField)
        {
            if ($filter === null)
            {
                $search_mode = self::SEARCH_MODE_OR_LIKE;
            }
            $entity_field_alias = $this->getJoinAliasForEntityField($queryBuilder, $field, $index);
            if (\count($field->getEntityFields()) === 1)
            {
                $search_field = "{$entity_field_alias}.{$field->getEntityFields()[0]}";
            }
            else if ($filter && !($filter instanceof MultiSelectFilter) && $filter->getSearchField() === null)
            {
                throw new \Exception("It's unsupported to have multiple entity fields with a filter defined.");
            }
        }
        if ($filter !== null)
        {
            $search_field = $filter->getSearchField() ?? $search_field;
            if ($filter instanceof DateTimeFilter)
            {
                $search_mode = self::SEARCH_MODE_BETWEEN;
            }
            else if ($filter instanceof MultiSelectFilter && $filter->getSearchField() !== null)
            {
                $search_mode = self::SEARCH_MODE_IN;
            }
            else if ($filter->getMultiSelectEnabled())
            {
                $search_mode = !empty($search_value) && strpos($search_value, ',') !== false ? self::SEARCH_MODE_IN : self::SEARCH_MODE_EQUALS;
            }
            else if ($filter->getSearchType() == DatatableFilter::SEARCH_TYPE_EQUALS)
            {
                $search_mode = self::SEARCH_MODE_EQUALS;
            }
        }

        switch ($search_mode)
        {
            case self::SEARCH_MODE_LIKE:
                $queryBuilder->{$search_type}(" $search_field LIKE :ssearch{$index} ");
                $queryBuilder->setParameter("ssearch{$index}", "%{$search_value}%");
                break;
            case self::SEARCH_MODE_EQUALS:
                $queryBuilder->{$search_type}(" $search_field = :ssearch{$index} ");
                $queryBuilder->setParameter("ssearch{$index}", $search_value);
                break;
            case self::SEARCH_MODE_OR_LIKE:
                $or_statements = [];
                foreach ($field->getEntityFields() as $entity_search_field)
                {
                    $or_statements[] = "$entity_field_alias.$entity_search_field LIKE :ssearch{$index}";
                }
                $queryBuilder->{$search_type}(implode(' OR ', $or_statements));
                $queryBuilder->setParameter("ssearch{$index}", "%{$search_value}%");
                break;
            case self::SEARCH_MODE_IN:
                $parts = explode(',', $search_value);
                $search_values = [];
                foreach ($parts as $part)
                {
                    $search_values[] = trim($part);
                }
                $parameter_name = "ssearch_values{$index}";
                $queryBuilder->{$search_type}("$search_field IN (:$parameter_name)");
                $queryBuilder->setParameter($parameter_name, $search_values);
                break;
            case self::SEARCH_MODE_BETWEEN:
                $start = $filter->getDefaultStart();
                $end = $filter->getDefaultEnd();
                if ($search_value)
                {
                    $parts = explode(" - ", $search_value);
                    if (\count($parts) === 2)
                    {
                        $start = new \DateTime($parts[0]);
                        $end = new \DateTime($parts[1]);
                    }
                }

                if (false === $filter->isFilterTime())
                {
                    $start->setTime(0,0, 0);
                    $end->setTime(23, 59, 59);
                }
                else
                {
                    // make sure to get the full last minute
                    $end->setTime($end->format('H'), $end->format('i'), 59);
                }

                if ($field !== null && is_array($field) && current($field) instanceof DQLDatatableField)
                {
                    $field = current($field);
                    $search_field = $field->getField();
                }

                $queryBuilder->{$search_type}("$search_field >= :ssearch_start{$index} AND $search_field <= :ssearch_end{$index}");
                $queryBuilder->setParameter("ssearch_start{$index}", $start);
                $queryBuilder->setParameter("ssearch_end{$index}", $end);
                break;
        }
    }

    private function getJoinAliasForEntityField(\Doctrine\ORM\QueryBuilder $queryBuilder, EntityDatatableField $field, int $index): string
    {
        $joined_field_alias = null;
        foreach ($this->joins as $join)
        {
            if (strpos($join[0], $field->getField()) !== false)
            {
                $joined_field_alias = $join[1];
                break;
            }
        }
        if ($joined_field_alias === null)
        {
            $joined_field_alias = "cj{$index}";
            $queryBuilder->leftJoin($field->getField(), $joined_field_alias, Join::LEFT_JOIN);
        }

        return $joined_field_alias;
    }

    /**
     * convert object to array
     * @param object $object
     * @return array
     */
    protected function _toArray($object)
    {
        $reflectionClass = new \ReflectionClass(get_class($object));
        $array           = array();
        foreach ($reflectionClass->getProperties() as $property)
        {
            $property->setAccessible(true);
            $array[$property->getName()] = $property->getValue($object);
            $property->setAccessible(false);
        }
        return $array;
    }

    /**
     * add join
     *
     * @example:
     *      ->setJoin(
     *              'r.event',
     *              'e',
     *              \Doctrine\ORM\Query\Expr\Join::INNER_JOIN,
     *              'e.name like %test%')
     *
     * @param string $join_field
     * @param string $alias
     * @param string $type
     * @param string $cond
     *
     * @return DoctrineBuilder
     */
    public function addJoin($join_field, $alias, $type = Join::INNER_JOIN, $cond = '', $condition_type = Join::WITH)
    {
        $join_method   = $type == Join::INNER_JOIN ? "innerJoin" : "leftJoin";
        $this->queryBuilder->$join_method($join_field, $alias, $condition_type, $cond);
        $this->joins[] = array($join_field, $alias, $type, $cond);
        return $this;
    }

    /**
     * Using getSQL() all parameters are converted to ?, but we don't know what's where..
     * the Doctrine Query object has no public function to get the correct parameters
     * therefore I've constructed a solution using preg_match_all.. this makes sure that a DQL query
     * using the same parameters twice, will also be in the parameters twice
     * ex. DQL: SELECT q FROM Entity WHERE q.date < :now AND q.date > :now
     * ==  SQL: SELECT * FROM table WHERE q.date < ? AND q.date > ?
     * will be given parameters: ['2017-01-01 01:01:01', '2017-01-01 01:01:01']
     *
     * @param Query $query
     * @return array
     */
    public function getSQLParamsFromQuery(Query $query)
    {
        $dql = $query->getDQL();

        $matches = []; $params = [];
        preg_match_all("/[^:]{1}(\?[0-9]|:[a-z]+[a-zA-Z0-9_]*)([ ,)(=><.\n]|$)/", $dql, $matches);
        foreach ($matches[1] as $k=>$v) {
            $var_name = substr($v, 1);
            $params[$k] = $query->getParameter($var_name)->getValue();
            // exception here for datetime.. might be more exceptions needed here..
            if ($params[$k] instanceof \DateTime) {
                $params[$k] = $params[$k]->format('Y-m-d H:i:s');
            }
        }

        return $params;
    }

    /**
     * Helper to execute native SQL through Doctrine
     *
     * @param string $sql
     * @param array $params
     *
     * @return array
     */
    protected function executeNativeSQL($sql, $params)
    {
        // The $query->getSQL() function returns a ? for each value. Unfortunately the
        // $stmt->execute($params) argument expects either the ?1 or the :var format. We have
        // neither so we're manually changing each ? to the corresponding parameter..
        $pos = 0;
        while (($pos = strpos($sql, '?', $pos)) !== false) {
            $sql = sprintf("%s'%s'%s", substr($sql, 0, $pos), array_shift($params), substr($sql,$pos+1));
        }

        $stmt = $this->queryBuilder->getEntityManager()->getConnection()->prepare($sql);
        $result = $stmt->executeQuery();
        return $result->fetchAllAssociative();
    }

    /**
     * get total records
     *
     * @return integer
     */
    public function getTotalRecords(array $filter_fields=[])
    {
        $qb = clone $this->queryBuilder;
        $this->_addSearch($qb, $filter_fields);
        $qb->resetDQLPart('orderBy');

        // queries with having are very annoying.. but this will find them and use an SQL subquery to deal with them.
        // Note this same method could be used fully instead of the below, but I don't trust the code enough for
        // use in all of SPIN for now..
        if ($qb->getDQLPart('having'))
        {
            // in case of having, we need the OVER() function from native SQL
            $qb->select(" count(distinct {$this->fields['_identifier_']}) as sclr0");
            $query = $qb->getQuery();

            // annoying Doctrine version difference sclr0 vs sclr_0
            if (strpos($query->getSQL(), 'sclr_0') !== false)
            {
                $sclr = 'sclr_0';
            }
            else
            {
                $sclr = 'sclr0';
            }

            $params = $this->getSQLParamsFromQuery($query);
            $this->addForcedIndexesToQuery($query);
            // trick here is to use a subquery suming the sclr0 distinct count
            $sql = "SELECT SUM($sclr) as total FROM (" . $query->getSQL() . ") AS sumqry";
            $result = $this->executeNativeSQL($sql, $params);
            return (int)$result[0]['total'];
        }

        $gb = $qb->getDQLPart('groupBy');
        if (empty($gb) || !in_array($this->fields['_identifier_'], $gb))
        {
            $qb->select(" count({$this->fields['_identifier_']}) ");
            $query = $qb->getQuery();
            $this->addForcedIndexesToQuery($query);
            return $query->getSingleScalarResult();
        }
        else
        {
            $qb->resetDQLPart('groupBy');
            $qb->select(" count(distinct {$this->fields['_identifier_']}) ");
            $query = $qb->getQuery();
            $this->addForcedIndexesToQuery($query);
            return $query->getSingleScalarResult();
        }
    }

    /**
     * Add the sorting and ordering to the query
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function addSorting()
    {
        $request    = $this->request;
        $dql_fields = array_values($this->fields);

        // add sorting
        if ($request->get('iSortCol_0') !== null)
        {

            $order_field = current(explode(' as ', $dql_fields[$request->get('iSortCol_0')]));
        }
        else
        {
            $order_field = null;
        }
        $qb = clone $this->queryBuilder;

        if ($order_field !== null)
        {
            $field = $dql_fields[$request->get('iSortCol_0')];
            if ($field instanceof DQLDatatableField)
            {
                $qb->orderBy($field->getAlias(), $request->get('sSortDir_0', 'asc'));
            }
            else
            {
                $qb->orderBy($order_field, $request->get('sSortDir_0', 'asc'));
            }
        }
        else
        {
            $qb->resetDQLPart('orderBy');
        }
        return $qb;
    }

    /**
     * @param array $filter_fields
     * @return string
     * @throws \Exception
     */
    public function getQuery(array $filter_fields=[])
    {

        $qb = $this->addSorting();

        // extract alias selectors
        $select = array($this->entity_alias);
        foreach ($this->joins as $join)
        {
            if (strpos($join[0], "."))
            {
                $select[] = $join[1];
            }
        }
        $qb->select(implode(',', $select));
        // add specific selects
        $has_add_select = false;
        foreach ($this->fields as $field)
        {
            if ($field instanceof DQLDatatableField)
            {
                $has_add_select = true;
                $qb->addSelect(sprintf("%s as %s", $field->getField(), $field->getAlias()));
            }
        }

        // add search
        $this->_addSearch($qb, $filter_fields);
        $params = $qb->getParameters();

        // get results and process data formatting
        $query          = $qb->getQuery();

        return array($query->getDQL(), $params);
    }

    /**
     * @return \Doctrine\Common\Collections\ArrayCollection
     */
    public function getParameters()
    {
        return $this->queryBuilder->getParameters();
    }

    /**
     * get data
     *
     * @return array
     */
    public function getData(array $filter_fields=[])
    {
        $request    = $this->request;

        $qb = $this->addSorting();

        // extract alias selectors
        $select = array($this->entity_alias);
        foreach ($this->joins as $join)
        {
            if (strpos($join[0], "."))
            {
                $select[] = $join[1];
            }
        }
        $qb->select(implode(',', $select));

        // add specific selects
        $has_add_select = false;
        foreach ($this->fields as $field)
        {
            if ($field instanceof DQLDatatableField)
            {
                $has_add_select = true;
                $qb->addSelect(sprintf("%s as %s", $field->getField(), $field->getAlias()));
            }
        }

        // add search
        $this->_addSearch($qb, $filter_fields);

        $display_length = (int) $request->get('iDisplayLength');
        $display_start = (int) $request->get('iDisplayStart');
        if($display_length > 10000) //Magic!
        {
            $display_length = 10000;
        }
        if ($this->_lowest_entity_field_id !== null)
        {
            $id_qb = clone $qb;
            $id_qb->select(sprintf('%s AS id', $this->_lowest_entity_field_id));
            if ($display_length > 0)
            {
                $id_qb->setMaxResults($display_length)->setFirstResult($display_start);
            }
            $id_query = $id_qb->getQuery();
            $this->addForcedIndexesToQuery($id_query);

            $ids = $id_query->getArrayResult();
            $ids = array_column($ids, 'id');

            $qb->andWhere(sprintf('%s IN (:_ids_)', $this->_lowest_entity_field_id));
            $qb->setParameter('_ids_', array_values($ids));
        }
        elseif ($display_length > 0)
        {
            $qb->setMaxResults($display_length)->setFirstResult($display_start);
        }
        // get results and process data formatting
        $query          = $qb->getQuery();

        $this->addQueryHintsToQuery($query);
        if ($this->_lowest_entity_field_id === null)
        {
            $this->addForcedIndexesToQuery($query);
        }
        $objects         = $query->getResult(Query::HYDRATE_OBJECT);
        $data            = array();
        $entity_alias    = $this->entity_alias;
        $joins           = $this->joins;
        $__getParentChain = function($field_parts) use($entity_alias, $joins, &$__getParentChain) {
            foreach ($joins as $join)
            {
                // skip join with argument a class since we cannot handle them anyway :(
                if (false === strpos($join[0], '.') || false !== strpos($join[0], '\\')) {
                    continue;
                }

                // get join alias
                if ($field_parts instanceof DatatableField) {
                    $join_alias = $field_parts->getField();
                    if (strpos($join_alias, '.') !== false) {
                        $parts = explode('.', $join_alias);
                        $join_alias = $parts[0];
                    }
                }
                else {
                    $join_alias = $field_parts[0];
                }

                // find correct join by matching join alias
                if ($join[1] == $join_alias)
                {
                    // $join[0] is the join statement, something like lc.customer_card
                    $join_field_parts = explode('.', $join[0]);
                    if ($join_field_parts[0] == $entity_alias) {
                        return $join_field_parts[1];
                    } else {
                        return sprintf("%s.%s",
                            $__getParentChain($join_field_parts),
                            $join_field_parts[1]
                        );
                    }
                }
            }
        };

        $__getKey = function($field) use($entity_alias, $__getParentChain) {
            $has_alias = preg_match_all('~([A-z]?\.[A-z]+)?\sas~', $field, $matches);
            $_f        = ( $has_alias > 0 ) ? $matches[1][0] : $field;
            $parts        = explode('.', $_f);
            if ($parts[0] != $entity_alias)
            {
                return $__getParentChain($parts) . '.' . $parts[1];
            }
            return $parts[1];
        };
        $__getValue = function($prop, $object, $has_add_select, $field)use(&$__getValue, $__getKey) {
            if ($field instanceof DQLDatatableField)
            {
                return $object[$field->getAlias()];
            }
            elseif ($has_add_select)
            {
                $object = $object[0];
            }

            // with LEFT joins target object can be NULL, so simply return null then
            if ($object === null)
            {
                return null;
            }

            if (strpos($prop, '\\') !== false)
            {
                throw new CustomJoinFieldException($prop);
            }

            $strpos = strpos($prop, '.');
            if ($strpos > 0)
            {
                $_prop     = substr($prop, 0, strpos($prop, '.'));
                $ref_class = new \ReflectionClass($object);
                $property  = $ref_class->getProperty($_prop);
                $property->setAccessible(true);
                return $__getValue(substr($prop, strpos($prop, '.') + 1), $property->getValue($object), false, null);
            }
            elseif ($strpos === 0)
            {
                $prop = substr($prop, 1);
            }

            $ref_class = new \ReflectionClass($object);
            $property  = $ref_class->getProperty($prop);
            $property->setAccessible(true);
            return $property->getValue($object);
        };
        foreach ($objects as $object)
        {
            $item = array();
            foreach ($this->fields as $_field)
            {
                $item[] = $__getValue($__getKey($_field), $object, $has_add_select, $_field);
            }
            $data[] = $item;
        }
        return array($data, $objects);
    }

    /**
     * get entity name
     *
     * @return string
     */
    public function getEntityName()
    {
        return $this->entity_name;
    }

    /**
     * get entity alias
     *
     * @return string
     */
    public function getEntityAlias()
    {
        return $this->entity_alias;
    }

    /**
     * @return array
     */
    public function getJoins()
    {
        return $this->joins;
    }

    /**
     * get fields
     *
     * @return array
     */
    public function getFields()
    {
        return $this->fields;
    }

    /**
     * get order field
     *
     * @return string
     */
    public function getOrderField()
    {
        return $this->order_field;
    }

    /**
     * get order type
     *
     * @return string
     */
    public function getOrderType()
    {
        return $this->order_type;
    }

    /**
     * get doctrine query builder
     *
     * @return \Doctrine\ORM\QueryBuilder
     */
    public function getDoctrineQueryBuilder()
    {
        return $this->queryBuilder;
    }

    /**
     * set entity
     *
     * @param string $entity_name
     * @param string $entity_alias
     *
     * @return Datatable
     */
    public function setEntity($entity_name, $entity_alias)
    {
        $this->entity_name  = $entity_name;
        $this->entity_alias = $entity_alias;
        $this->queryBuilder->from($entity_name, $entity_alias);
        return $this;
    }

    /**
     * set fields
     *
     * @param DatatableField[]|array $fields
     *
     * @return Datatable
     */
    public function setFields(array $fields)
    {
        $this->fields = $fields;
        $this->queryBuilder->select(implode(', ', $fields));
        return $this;
    }

    /**
     * set order
     *
     * @param string $order_field
     * @param string $order_type
     *
     * @return Datatable
     */
    public function setOrder($order_field, $order_type)
    {
        $this->order_field = $order_field;
        $this->order_type  = $order_type;
        $this->queryBuilder->orderBy($order_field, $order_type);
        return $this;
    }

    /**
     * set fixed data
     *
     * @param array|null $data
     *
     * @return Datatable
     */
    public function setFixedData($data)
    {
        $this->fixed_data = $data;
        return $this;
    }

    /**
     * set query where
     *
     * @param string $where
     * @param array  $params
     *
     * @return Datatable
     */
    public function setWhere($where, array $params = array())
    {
        $this->queryBuilder->where($where);
        foreach ($params as $param_key => $param_value)
        {
            $this->queryBuilder->setParameter($param_key, $param_value);
        }
        return $this;
    }

    /**
     * set query group
     *
     * @param string $group
     *
     * @return Datatable
     */
    public function setGroupBy($group)
    {
        $this->queryBuilder->groupBy($group);
        return $this;
    }

    /**
     * set search
     *
     * @param bool $search
     *
     * @return Datatable
     */
    public function setSearch($search)
    {
        $this->search = $search;
        return $this;
    }

    /**
     * set doctrine query builder
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     *
     * @return DoctrineBuilder
     */
    public function setDoctrineQueryBuilder(\Doctrine\ORM\QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
        return $this;
    }

    /**
     * @param string $hint
     * @param bool $value
     *
     * @return DoctrineBuilder
     */
    public function AddQueryHint($hint, $value)
    {
        $this->query_hints[$hint] = $value;
        return $this;
    }

    /**
     * @return array
     */
    public function getQueryHints()
    {
        return $this->query_hints;
    }

    /**
     * @param Query $query
     */
    private function addQueryHintsToQuery(Query $query)
    {
        if (\count($this->query_hints) === 0)
        {
            return;
        }

        foreach($this->query_hints as $query_hint => $value)
        {
            $query->setHint($query_hint, $value);
        }
    }

    public function setExperimentalQuerying($lowest_entity_field_id)
    {
        $this->_lowest_entity_field_id = $lowest_entity_field_id;
    }

    /**
     * @param Query $query
     */
    private function addForcedIndexesToQuery(Query $query)
    {
        if (empty($this->forced_indexes))
        {
            return;
        }

        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, HintDrivenSqlWalker::class);
        $query->setHint(UseIndexHintHandler::class, $this->forced_indexes);
    }

    /**
     * add query where
     *
     * @param string $where
     *
     */
    public function andWhere($where)
    {
        $this->queryBuilder->andWhere($where);
    }

    /**
     * add query parameter
     *
     * @param $key
     * @param $value
     */
    public function setParameter($key, $value)
    {
        $this->queryBuilder->setParameter($key, $value);
    }

    function addForcedIndex(string $table_name, string $index_name): void
    {
        $this->forced_indexes[] = IndexHint::force($index_name, $table_name);
    }
}