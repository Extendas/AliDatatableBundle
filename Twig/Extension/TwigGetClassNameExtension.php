<?php
/**
 * Created by PhpStorm.
 * User: Maarten
 * Date: 12-5-2017
 * Time: 17:26
 */

namespace Ali\DatatableBundle\Twig\Extension;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TwigGetClassNameExtension extends AbstractExtension
{
    /**
     * @return array
     */
    public function getFilters()
    {
        return [
            new TwigFilter('get_class_name', [$this, 'getClassNameFilter']),
        ];
    }

    /**
     * @param $object
     *
     * @return string
     */
    public function getClassNameFilter($object)
    {
        return get_class($object);
    }

}