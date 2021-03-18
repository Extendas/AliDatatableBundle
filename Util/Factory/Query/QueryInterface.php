<?php

namespace Ali\DatatableBundle\Util\Factory\Query;

use Doctrine\ORM\Query\Expr\Join;

interface QueryInterface
{

    /**
     * get total records
     * 
     * @return integer 
     */
    function getTotalRecords(array $filter_fields=[]);

    /**
     * Add the sorting and ordering to the query
     *
     * @return mixed
     */
    function addSorting();

    /**
     * get sql query
     *
     * @return string
     */
    function getQuery(array $filter_fields=[]);

    /**
     * get data
     * 
     * @return array
     */
    function getData(array $filter_fields=[]);

    /**
     * set entity
     * 
     * @param string $entity_name
     * @param string $entity_alias
     * 
     * @return Datatable 
     */
    function setEntity($entity_name, $entity_alias);

    /**
     * set fields
     * 
     * @param array $fields
     * 
     * @return Datatable 
     */
    function setFields(array $fields);

    /**
     * get entity name
     * 
     * @return string
     */
    function getEntityName();

    /**
     * get entity alias
     * 
     * @return string
     */
    function getEntityAlias();

    /**
     * Get query parameters
     *
     * @return array
     */
    function getParameters();

    /**
     * Get joins
     *
     * @return array
     */
    function getJoins();

    /**
     * get fields
     * 
     * @return array
     */
    function getFields();

    /**
     * get order field
     *
     * @return string
     */
    function getOrderField();

    /**
     * get order type
     * 
     * @return string
     */
    function getOrderType();

    /**
     * set order
     * 
     * @param string $order_field
     * @param string $order_type
     * 
     * @return Datatable 
     */
    function setOrder($order_field, $order_type);

    /**
     * set fixed data
     * 
     * @param type $data
     * 
     * @return Datatable 
     */
    function setFixedData($data);

    /**
     * set query where
     * 
     * @param string $where
     * @param array  $params
     * 
     * @return Datatable 
     */
    function setWhere($where, array $params = array());

    /**
     * set search
     *
     * @param bool $search
     *
     * @return Datatable
     */
    function setSearch($search);

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
    function addJoin($join_field, $alias, $type = Join::INNER_JOIN, $cond = '');

    /**
     * @param $hint
     * @param $value
     *
     * @return DoctrineBuilder
     */
    function AddQueryHint($hint, $value);

    function addForcedIndex($alias, $force_index);
}
