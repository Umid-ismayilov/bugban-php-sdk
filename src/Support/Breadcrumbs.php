<?php

namespace Bugban\Sdk\Support;

class Breadcrumbs
{
    /** @var array */
    private $items = array();

    /** @var int */
    private $max;

    public function __construct($max = 25)
    {
        $this->max = (int) $max;
    }

    public function add($message, $category = 'default', array $data = array(), $level = 'info')
    {
        $this->items[] = array(
            'message' => $message,
            'category' => $category,
            'level' => $level,
            'data' => $data,
            'timestamp' => microtime(true),
        );

        if (count($this->items) > $this->max) {
            array_shift($this->items);
        }
    }

    public function all()
    {
        return $this->items;
    }

    public function clear()
    {
        $this->items = array();
    }
}
