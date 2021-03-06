<?php

namespace Ali\DatatableBundle\Util\Factory\Query;

use Ali\DatatableBundle\Util\Datatable;
use Ali\DatatableBundle\Util\Exceptions\CustomJoinFieldException;
use Ali\DatatableBundle\Util\Factory\Fields\DatatableField;
use Ali\DatatableBundle\Util\Factory\Fields\EntityDatatableField;
use Ali\DatatableBundle\Util\Factory\Filter\DatatableFilter;
use Ali\DatatableBundle\Util\Factory\Filter\DateTimeFilter;
use Ali\DatatableBundle\Util\Factory\Filter\MultiSelectFilter;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Ggergo\SqlIndexHintBundle\SqlIndexWalker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Ali\DatatableBundle\Util\Factory\Fields\DQLDatatableField;

class DoctrineBuilder implements QueryInterface
{

    /** @var \Symfony\Component\DependencyInjection\ContainerInterface */
    protected $container;

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

    /** @var array */
    protected $forced_indexes;

    /** @var string|null */
    private $_lowest_entity_field_id;

    /**
     * class constructor
     *
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container, $em)
    {
        $this->container    = $container;
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
                $is_filter_field_with_equals = isset($filter_fields[$i]) && $filter_fields[$i]->getSearchType() == DatatableFilter::SEARCH_TYPE_EQUALS;
                $equals_operator = $is_filter_field_with_equals ? '=' : 'like';

                $is_required_date_filter = isset($filter_fields[$i]) && $filter_fields[$i] instanceof DateTimeFilter && $filter_fields[$i]->isRequired();
                if ($search_param !== false && $search_param != '' || $is_required_date_filter)
                {
                    $field        = explode(' ', trim($search_field));
                    $search_field = $field[0];

                    /** @var DatatableField[] $original_field */
                    $original_field = array_slice($this->fields, $i, 1);
                    if (isset($filter_fields[$i]) && $filter_fields[$i] instanceof DateTimeFilter)
                    {
                        $parts = explode(" - ", $search_param);
                        $start = $filter_fields[$i]->getDefaultStart();
                        $end = $filter_fields[$i]->getDefaultEnd();
                        if (\count($parts) === 2)
                        {
                            $start = new \DateTime($parts[0]);
                            $end = new \DateTime($parts[1]);
                        }

                        if (false === $filter_fields[$i]->isFilterTime())
                        {
                            $start->setTime(0,0, 0);
                            $end->setTime(23, 59, 59);
                        }
                        else
                        {
                            // make sure to get the full last minute
                            $end->setTime($end->format('H'), $end->format('i'), 59);
                        }

                        if ($original_field !== null && is_array($original_field) && current($original_field) instanceof DQLDatatableField)
                        {
                            $field = current($original_field);
                            $search_field = $field->getField();
                        }

                        $queryBuilder->andWhere("$search_field >= :ssearch_start{$i} AND $search_field <= :ssearch_end{$i}");
                        $queryBuilder->setParameter("ssearch_start{$i}", $start);
                        $queryBuilder->setParameter("ssearch_end{$i}", $end);

                        continue;
                    }
                    elseif (isset($filter_fields[$i]) && $filter_fields[$i] instanceof MultiSelectFilter)
                    {
                        $search_field = $filter_fields[$i]->getSearchField();
                        $parts = explode(',', $search_param);

                        $ors = [];
                        foreach ($parts as $k=>$part)
                        {
                            $ors[] = "$search_field = :ssearch{$i}_part{$k}";
                            $queryBuilder->setParameter("ssearch{$i}_part{$k}", trim($part));
                        }
                        $queryBuilder->andWhere(implode(' OR ', $ors));

                        continue;
                    }
                    elseif ($original_field !== null && is_array($original_field) && current($original_field) instanceof DQLDatatableField)
                    {
                        $original_field = current($original_field);
                        $search_field = $original_field->getField();
                        if ($original_field->getNeedsHaving()) {
                            $queryBuilder->andHaving(" $search_field $equals_operator :ssearch{$i} ");
                        } else {
                            $search_field = $original_field->getField();
                            $queryBuilder->andWhere(" $search_field $equals_operator :ssearch{$i} ");
                        }
                    }
                    elseif ($original_field !== null && is_array($original_field) && reset($original_field) instanceof EntityDatatableField && reset($original_field)->getEntityFields() != null)
                    {
                        // 1. get the entity fields
                        $entity_search_fields = reset($original_field)->getEntityFields();

                        // 2. join if needed
                        $joined_field_alias = null;
                        foreach ($this->joins as $join)
                        {
                            if (strpos($join[0], $search_field) !== FALSE)
                            {
                                $joined_field_alias = $join[1];
                                break;
                            }
                        }
                        if ($joined_field_alias === null)
                        {
                            $joined_field_alias = 'cj'.$i;
                            $queryBuilder->leftJoin($search_field, $joined_field_alias, Join::LEFT_JOIN);
                        }

                        //3. check if we received an array with search fields
                        if(!is_array($entity_search_fields))
                        {
                            throw new \Exception('Expected an array with fields as answer from the "EntityDatatableField->getEntityFields()" method which you passed in the constructor or in the setter.');
                        }

                        //4. build the where part of the query (WHERE field LIKE entity_search_field[0] OR WHERE field LIKE entity_search_field[1] OR.....)
                        $first_field = true;
                        $query = '';
                        foreach ($entity_search_fields as $key => $entity_search_field)
                        {
                            if ($first_field === false)
                            {
                                $query .= 'OR';
                            }
                            $query .= " $joined_field_alias.$entity_search_field $equals_operator :ssearch{$i} ";
                            $first_field = false;
                        }
                        $queryBuilder->andWhere($query);
                    }
                    else
                    {
                        $queryBuilder->andWhere(" $search_field $equals_operator :ssearch{$i} ");
                    }

                    $queryBuilder->setParameter("ssearch{$i}", $equals_operator == '=' ? $request->get("sSearch_{$i}") : '%' . $request->get("sSearch_{$i}") . '%');
                }
            }
        }
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
     * @return Datatable
     */
    public function addJoin($join_field, $alias, $type = Join::INNER_JOIN, $cond = '')
    {
        if ($cond != '')
        {
            $cond = " with {$cond} ";
        }
        $join_method   = $type == Join::INNER_JOIN ? "innerJoin" : "leftJoin";
        $this->queryBuilder->$join_method($join_field, $alias, null, $cond);
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
        $stmt->execute();
        return $stmt->fetchAll();
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
        $this->queryBuilder->setParameters($params);
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

        $query->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, SqlIndexWalker::class);
        $query->setHint(SqlIndexWalker::HINT_INDEX, $this->forced_indexes);
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

    /**
     * @param $alias
     * @param $force_index
     */
    function addForcedIndex($alias, $force_index)
    {
        $this->forced_indexes[$alias] = sprintf('FORCE INDEX (%s)', $force_index);
    }
}