<?php
namespace ZendAnnotationRouter\Annotation;

/**
 * @Annotation
 */
class Route
{
    
    /**
     * @var string
     */
    public $name;
    
    /**
     * @var string
     */
    public $type = 'literal';
    
    /**
     * @var string
     */
    public $route;
    
    /**
     * @var array
     */
    public $defaults = array();
    
    /**
     * @var array
     */
    public $constraints = array();
    
    /**
     * @var bool
     */
    public $mayTerminate = true;
    
    /**
     * @var string
     */
    public $extends = '';
    
    /**
     * @var integer
     */
    public $priority = 0;
    
}

?>