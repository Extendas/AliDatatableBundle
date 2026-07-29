<?php

namespace Ali\DatatableBundle\Util;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormRendererInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\EntityManager;
use Ali\DatatableBundle\Util\Factory\Query\QueryInterface;
use Ali\DatatableBundle\Util\Factory\Query\DoctrineBuilder;
use Ali\DatatableBundle\Util\Formatter\Renderer;
use Ali\DatatableBundle\Util\Factory\Prototype\PrototypeBuilder;
use Twig\Environment;
use Twig\Markup;

class Datatable
{

    /** @var array */
    protected $_fixed_data = NULL;

    /** @var array */
    protected $_config;

    /** @var boolean */
    protected $_has_action;

    /** @var boolean */
    protected $_has_renderer_action = false;

    /** @var array */
    protected $_multiple;

    /** @var \Ali\DatatableBundle\Util\Factory\Query\QueryInterface */
    protected $_queryBuilder;

    /** @var \Symfony\Component\HttpFoundation\Request */
    protected $_request;

    /** @var closure */
    protected $_renderer = NULL;

    /** @var array */
    protected $_renderers = NULL;

    /** @var Renderer */
    protected $_renderer_obj = null;

    /** @var boolean */
    protected $_search;

    /** @var array */
    protected $_search_fields = array();

    /** @var array */
    protected $_filter_fields = array();

    /** @var array */
    protected static $_instances = array();

    /** @var Datatable */
    protected static $_current_instance = NULL;

    private FormFactoryInterface $form_factory;
    private Environment $twig;
    private FormRendererInterface $form_renderer;
    private EntityManagerInterface $entity_manager;

    public function __construct(ParameterBagInterface $parameter_bag, FormFactoryInterface $form_factory, FormRendererInterface $form_renderer, Environment $twig, EntityManagerInterface $entity_manager)
    {
        $this->form_factory         = $form_factory;
        $this->form_renderer        = $form_renderer;
        $this->entity_manager       = $entity_manager;
        $this->twig                 = $twig;
        $this->_config              = $parameter_bag->get('ali_datatable');
        $this->_request             = Request::createFromGlobals();
        self::$_current_instance    = $this;
        $this->_applyDefaults();
    }

    /**
     * apply default value from datatable config
     *
     * @return void
     */
    protected function _applyDefaults()
    {
        if (isset($this->_config['all']))
        {
            $this->_has_action = $this->_config['all']['action'];
            $this->_search     = $this->_config['all']['search'];
        }
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
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function addJoin($join_field, $alias, $type = Join::INNER_JOIN, $cond = '')
    {
        $this->_queryBuilder->addJoin($join_field, $alias, $type, $cond);
        return $this;
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
     * @param null $force_index
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function addJoinWithForcedIndex($join_field, $alias, $type = Join::INNER_JOIN, $cond = '', ?string $force_index = null, ?string $force_index_class = null)
    {
        $this->addJoin($join_field, $alias, $type, $cond);
        if ($force_index !== null)
        {
            $table_name = $this->entity_manager->getClassMetadata($force_index_class)->getTableName();
            $this->getQueryBuilder()->addForcedIndex($table_name, $force_index);
        }
        return $this;
    }

    /**
     * execute
     *
     * @return JsonResponse
     */
    public function execute()
    {
        $request       = $this->_request;
        $total_count = $this->_queryBuilder->getTotalRecords($this->getFilterFields());
        [$data, $objects] = $this->_queryBuilder->getData($this->getFilterFields());

        $id_index      = array_search('_identifier_', array_keys($this->getFields()));
        $ids           = array();
        array_walk($data, function($val, $key) use ($id_index, &$ids) {
            $ids[$key] = $val[$id_index];
        });
        if (!is_null($this->_fixed_data))
        {
            $this->_fixed_data = array_reverse($this->_fixed_data);
            foreach ($this->_fixed_data as $item)
            {
                array_unshift($data, $item);
            }
        }
        if (!is_null($this->_renderer))
        {
            $raw_data = $data;
            array_walk($data, $this->_renderer);
            $this->_markRendererOutputAsSafe($data, $raw_data);
        }
        if (!is_null($this->_renderer_obj))
        {
            // ->setRenderers() always renders every column through Twig (falling back to
            // _default.html.twig's {{ dt_item }}), so auto-escaping is guaranteed here.
            $this->_renderer_obj->applyTo($data, $objects);
        }
        else
        {
            // No Twig pass is guaranteed to run (no ->setRenderers()), so nothing will
            // auto-escape leftover raw entity/DB values (or a whole untouched row when
            // ->setRenderer() isn't used either). Escape them now instead of relying on
            // a pass that may never happen.
            $this->_escapeUnsafeValues($data);
        }
        if (!empty($this->_multiple))
        {
            array_walk($data, function($val, $key) use(&$data, $ids) {
                $safe_id = htmlspecialchars((string) $ids[$key], ENT_QUOTES, 'UTF-8');
                array_unshift($val, "<input type='checkbox' name='dataTables[actions][]' value='{$safe_id}' />");
                $data[$key] = $val;
            });
        }
        $output = array(
            "sEcho"                => intval($request->get('sEcho')),
            "iTotalRecords"        => $total_count,
            "iTotalDisplayRecords" => $total_count,
            "aaData"               => $data
        );
        return new JsonResponse($output);
    }

    /**
     * Column values untouched by the controller's ->setRenderer() closure are raw entity/DB
     * data. They are left as plain strings here and escaped later, either by Twig
     * (if ->setRenderers() is also configured, see execute()) or by _escapeUnsafeValues().
     * Values the closure *did* rewrite are trusted, deliberately-built HTML (links, badges, ...),
     * so we wrap them in Twig\Markup - the same "already safe" marker the |raw filter produces -
     * so neither a later Twig pass nor _escapeUnsafeValues() mangles them.
     *
     * @param array $data     data after the renderer closure ran (modified in place)
     * @param array $raw_data data as it was before the renderer closure ran
     *
     * @return void
     */
    private function _markRendererOutputAsSafe(array &$data, array $raw_data)
    {
        foreach ($data as $row_index => &$row)
        {
            foreach ($row as $column_index => &$value)
            {
                $original = $raw_data[$row_index][$column_index] ?? null;
                if (is_string($value) && $value !== $original)
                {
                    $value = new Markup($value, 'UTF-8');
                }
            }
        }
    }

    /**
     * Escapes any plain string cell value so raw entity/DB data can never be interpreted as
     * HTML/script by the client-side DataTables grid, which inserts aaData directly into the
     * DOM. Used whenever no ->setRenderers() Twig pass is configured to guarantee escaping -
     * i.e. when the table has no renderer at all, or only a ->setRenderer() closure that may
     * leave some columns (or all rows, for columns it doesn't touch) unrendered.
     * Values already wrapped in Twig\Markup by _markRendererOutputAsSafe() are deliberately
     * built HTML and are left untouched.
     *
     * @param array $data data after any ->setRenderer() closure ran (modified in place)
     *
     * @return void
     */
    private function _escapeUnsafeValues(array &$data)
    {
        foreach ($data as &$row)
        {
            foreach ($row as &$value)
            {
                if (is_string($value))
                {
                    $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                }
            }
        }
    }

    /**
     * get datatable instance by id
     *  return current instance if null
     *
     * @param string $id
     *
     * @return \Ali\DatatableBundle\Util\Datatable .
     */
    public static function getInstance($id)
    {
        $instance = NULL;
        if (array_key_exists($id, self::$_instances))
        {
            $instance = self::$_instances[$id];
        }
        else
        {
            $instance = self::$_current_instance;
        }

        if (is_null($instance))
        {
            throw new \Exception('No instance found for datatable, you should set a datatable id in your
            action with "setDatatableId" using the id from your view ');
        }

        return $instance;
    }

    /**
     * get entity name
     *
     * @return string
     */
    public function getEntityName()
    {
        return $this->_queryBuilder->getEntityName();
    }

    /**
     * get entity alias
     *
     * @return string
     */
    public function getEntityAlias()
    {
        return $this->_queryBuilder->getEntityAlias();
    }

    /**
     * get fields
     *
     * @return array
     */
    public function getFields()
    {
        return $this->_queryBuilder->getFields();
    }

    public static function clearInstances()
    {
        static::$_instances = array();
    }

    /**
     * get has_action
     *
     * @return boolean
     */
    public function getHasAction()
    {
        return $this->_has_action;
    }

    /**
     * retrun true if the actions column is overridden by twig renderer
     *
     * @return boolean
     */
    public function getHasRendererAction()
    {
        return $this->_has_renderer_action;
    }

    /**
     * get order field
     *
     * @return string
     */
    public function getOrderField()
    {
        return $this->_queryBuilder->getOrderField();
    }

    /**
     * get order type
     *
     * @return string
     */
    public function getOrderType()
    {
        return $this->_queryBuilder->getOrderType();
    }

    /**
     * create raw prototype
     *
     * @param string $type
     *
     * @return PrototypeBuilder
     */
    public function getPrototype($type)
    {
        return new PrototypeBuilder($this->form_factory, $this->form_renderer, $type);
    }

    /**
     * get query builder
     *
     * @return QueryInterface
     */
    public function getQueryBuilder()
    {
        return $this->_queryBuilder;
    }

    /**
     * get search
     *
     * @return boolean
     */
    public function getSearch()
    {
        return $this->_search;
    }

    /**
     * set entity
     *
     * @param string $entity_name
     * @param string $entity_alias
     * @param null $forced_index
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function setEntity($entity_name, $entity_alias, $forced_index = null)
    {
        $this->_queryBuilder->setEntity($entity_name, $entity_alias);
        if ($forced_index !== null)
        {
            $table_name = $this->entity_manager->getClassMetadata($entity_name)->getTableName();
            $this->getQueryBuilder()->addForcedIndex($table_name, $forced_index);
        }
        return $this;
    }

    public function setEntityManager(EntityManagerInterface $em): Datatable
    {
        $this->_queryBuilder = new DoctrineBuilder($em);
        return $this;
    }

    public function setFields(array $fields): Datatable
    {
        $this->_queryBuilder->setFields($fields);
        return $this;
    }

    /**
     * Add a field
     *
     * @param string $key
     * @param string $value
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function addField($key, $value)
    {
        $fields       = $this->_queryBuilder->getFields() ? $this->_queryBuilder->getFields() : array();
        $fields[$key] = $value;
        $this->setFields($fields);
        return $this;
    }

    /**
     * Add an array of fields
     *
     * @param array $fields
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function addFields(array $fields)
    {
        $oldFields = $this->_queryBuilder->getFields() ? $this->_queryBuilder->getFields() : array();
        $newFields = array_merge($oldFields, $fields);
        $this->setFields($newFields);
        return $this;
    }

    /**
     * set has action
     *
     * @param boolean $has_action
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function setHasAction($has_action)
    {
        $this->_has_action = $has_action;
        return $this;
    }

    /**
     * set order
     *
     * @param string $order_field
     * @param string $order_type
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function setOrder($order_field, $order_type)
    {
        $this->_queryBuilder->setOrder($order_field, $order_type);
        return $this;
    }

    /**
     * set fixed data
     *
     * @param null|array $data
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function setFixedData($data)
    {
        $this->_fixed_data = $data;
        return $this;
    }

    /**
     * set query builder
     *
     * @param QueryInterface $queryBuilder
     */
    public function setQueryBuilder(QueryInterface $queryBuilder)
    {
        $this->_queryBuilder = $queryBuilder;
    }

    /**
     * set a php closure as renderer
     *
     * @example:
     *
     *  $controller_instance = $this;
     *  $datatable = $this->get('datatable')
     *       ->setEntity("AliBaseBundle:Entity", "e")
     *       ->setFields($fields)
     *       ->setOrder("e.created", "desc")
     *       ->setRenderer(
     *               function(&$data) use ($controller_instance)
     *               {
     *                   foreach ($data as $key => $value)
     *                   {
     *                       if ($key == 1)
     *                       {
     *                           $data[$key] = $controller_instance
     *                               ->get('templating')
     *                               ->render('AliBaseBundle:Entity:_decorator.html.twig',
     *                                       array(
     *                                           'data' => $value
     *                                       )
     *                               );
     *                       }
     *                   }
     *               }
     *         )
     *       ->setHasAction(true);
     *
     * @param \Closure $renderer
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function setRenderer(\Closure $renderer)
    {
        $this->_renderer = $renderer;
        return $this;
    }

    /**
     * set renderers as twig views
     *
     * @example: To override the actions column
     *
     *      ->setFields(
     *          array(
     *             "field label 1" => 'x.field1',
     *             "field label 2" => 'x.field2',
     *             "_identifier_"  => 'x.id'
     *          )
     *      )
     *      ->setRenderers(
     *          array(
     *             2 => array(
     *               'view' => 'AliDatatableBundle:Renderers:_actions.html.twig',
     *               'params' => array(
     *                  'edit_route'    => 'matche_edit',
     *                  'delete_route'  => 'matche_delete',
     *                  'delete_form_prototype'   => $datatable->getPrototype('delete_form')
     *               ),
     *             ),
     *          )
     *       )
     *
     * @param array $renderers
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function setRenderers(array $renderers)
    {
        $this->_renderers = $renderers;
        if (!empty($this->_renderers))
        {
            $this->_renderer_obj = new Renderer($this->twig, $this->_renderers, $this->getFields());
        }
        $actions_index = array_search('_identifier_', array_keys($this->getFields()));
        if ($actions_index != FALSE && isset($renderers[$actions_index]))
        {
            $this->_has_renderer_action = true;
        }
        return $this;
    }

    /**
     * set query where
     *
     * @param string $where
     * @param array  $params
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function setWhere($where, array $params = array())
    {
        $this->_queryBuilder->setWhere($where, $params);
        return $this;
    }

    /**
     * set query group
     *
     * @param string $groupbywhere
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function setGroupBy($groupby)
    {
        $this->_queryBuilder->setGroupBy($groupby);
        return $this;
    }

    /**
     * set search
     *
     * @param bool $search
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function setSearch($search)
    {
        $this->_search = $search;
        $this->_queryBuilder->setSearch($search);
        return $this;
    }

    /**
     * set datatable identifier
     *
     * @param string $id
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function setDatatableId($id)
    {
        if (!array_key_exists($id, self::$_instances))
        {
            self::$_instances[$id] = $this;
        }
        else
        {
            throw new \Exception('Identifer already exists');
        }
        return $this;
    }

    /**
     * get multiple
     *
     * @return array
     */
    public function getMultiple()
    {
        return $this->_multiple;
    }

    /**
     * set multiple
     *
     * @example
     *
     *  ->setMultiple('delete' => array ('title' => "Delete", 'route' => 'route_to_delete' ));
     *
     * @param array $multiple
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function setMultiple(array $multiple)
    {
        $this->_multiple = $multiple;
        return $this;
    }

    /**
     * get global configuration ( read it from config.yml under ali_datatable)
     *
     * @return array
     */
    public function getConfiguration()
    {
        return $this->_config;
    }

    /**
     * get search field
     *
     * @return array
     */
    public function getSearchFields()
    {
        return $this->_search_fields;
    }

    /**
     * set search fields
     *
     * @example
     *
     *      ->setSearchFields(array(0,2,5))
     *
     * @param array $search_fields
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function setSearchFields(array $search_fields)
    {
        $this->_search_fields = $search_fields;
        return $this;
    }

    /**
     * add query where
     *
     * @param string $where
     *
     * @return Datatable
     */
    public function andWhere($where)
    {
        $this->_queryBuilder->andWhere($where);
        return $this;
    }

    /**
     * add query parameter
     *
     * @param $key
     * @param $value
     *
     * @return Datatable
     */
    public function setParameter($key, $value)
    {
        $this->_queryBuilder->setParameter($key, $value);
        return $this;
    }

    /**
     * get filter field
     *
     * @return array
     */
    public function getFilterFields()
    {
        return $this->_filter_fields;
    }

    /**
     * set filter fields
     *
     * @example
     *
     *      ->setFilterFields(array(
     *                3 => new DatatableFilter(array(
     *                    new DatatableFilterValue(0, $translator->trans('option.no')),
     *                   new DatatableFilterValue(1, $translator->trans('option.yes'))
     *                ))
     *                4 => new DatatableFilter(array(
     *                    new DatatableFilterValue(0, $translator->trans('option.no')),
     *                    new DatatableFilterValue(1, $translator->trans('option.yes'))
     *                ))
     *
     * @param array $filter_fields
     *
     * @return \Ali\DatatableBundle\Util\Datatable
     */
    public function setFilterFields(array $filter_fields)
    {
        $this->_filter_fields = $filter_fields;
        return $this;
    }

    public function AddQueryHint($hint, $value)
    {
        $this->_queryBuilder->addQueryHint($hint, $value);
        return $this;
    }

    public function setExperimentQuerying($lowest_entity_field_id)
    {
        $this->_queryBuilder->setExperimentalQuerying($lowest_entity_field_id);
    }
}
