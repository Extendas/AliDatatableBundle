<?php

namespace Ali\DatatableBundle\Util\Factory\Filter;

/**
 * Class DateTimeFilter
 *
 * Note: this Filter is unfortunately not plug-and-play. For an example of how it can be used, see:
 * https://github.com/Extendas/SPIN/blob/develop/apps/spin/Resources/AliDatatableBundle/views/Internal/script.html.twig
 * and
 * https://github.com/Extendas/SPIN/blob/develop/src/Extendas/SpinBundle/Resources/views/Main/Datatable/table_layout.html.twig
 *
 * @author Rein Baarsma <rein@solidwebcode.com>
 */
class DateTimeFilter extends DatatableFilter
{
    protected bool $is_filter_time = false;
    protected bool $is_required = false;
    protected \DateTime $default_start;
    protected \DateTime $default_end;

    public function __construct(bool $is_filter_time = false, bool $is_required = false, ?\DateTime $default_start = null, ?\DateTime $default_end = null, bool $use_default_start_time = true, bool $use_default_end_time = true)
    {
        $this->is_filter_time   = $is_filter_time;
        $this->is_required      = $is_required;
        $this->default_start    = $default_start ?: new \DateTime;
        $this->default_end      = $default_end ?: new \DateTime;
        if ($use_default_start_time)
        {
            $this->default_start->setTime(0, 0, 0);
        }
        if ($use_default_end_time)
        {
            $this->default_end->setTime(23, 59, 59);
        }
        parent::__construct([]);
    }

    public function isFilterTime(): bool
    {
        return $this->is_filter_time;
    }

    public function isRequired(): bool
    {
        return $this->is_required;
    }

    public function getDefaultStart(): \DateTime
    {
        return $this->default_start;
    }

    public function getDefaultEnd(): \DateTime
    {
        return $this->default_end;
    }
}