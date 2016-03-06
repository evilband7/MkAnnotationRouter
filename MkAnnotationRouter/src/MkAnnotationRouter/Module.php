<?php
namespace MkAnnotationRouter;

use Zend\ModuleManager\ModuleManager;
use Zend\ModuleManager\ModuleEvent;
use Doctrine\Common\Annotations\AnnotationReader;
use MkAnnotationRouter\Annotation\Route;
use Zend\Filter\Word\CamelCaseToDash;
use PhpCommonUtil\Util\Assert;
use Zend\Stdlib\ArrayUtils;

class Module
{
    
    public function getConfig(){
        return include __DIR__ . '/../../config/module.config.php';
    }
    
    public function init(ModuleManager  $moduleManager)
    {
        $events = $moduleManager->getEventManager();
        $events->attach(ModuleEvent::EVENT_MERGE_CONFIG, array($this, 'onMergeConfig'), 100);
    }
    
    public function onMergeConfig(ModuleEvent $e)
    {
        $configListener             = $e->getConfigListener();
        $config                     = $configListener->getMergedConfig(false);
        $mkAnnotationRouterConfig   = $config['mk_annotation_router'];
        $controllers                = &$config['controllers'];
        $reader                     = new AnnotationReader();
        
        // GENERATE PROJECT CONTROLLER CONFIG
        if($mkAnnotationRouterConfig['generate_project_controller_config']){
            $moduleDirs = $mkAnnotationRouterConfig['module_directory']; 
            $moduleDirs = rtrim($moduleDirs, '/');
            if ( empty($moduleDirs) ){
                $moduleDirs = getcwd() . '/module';
            }
            $moduleDirs .= '/*';
            $moduleDirs = glob( $moduleDirs , GLOB_ONLYDIR);
            foreach ($moduleDirs as $moduleDir){
                $moduleName = basename($moduleDir);
                $controllerFiles = glob( "$moduleDir/src/$moduleName/Controller/*Controller.php");
                if($controllerFiles && is_array($controllerFiles)){
                    foreach ($controllerFiles as $controller){
                        $className = preg_replace('/\\.[^.\\s]{3,4}$/', '', basename($controller));
                        $controllerClass = "$moduleName\\Controller\\$className";
                        $config['controllers']['invokables'][$controllerClass] = $controllerClass;
                    }
                }
            }
        }
        
        // GENERATE ROUTES
        $routeToMergs = array();
        $methodRoutesToMerg = array();
        
        foreach ( $controllers['invokables'] as $controllerAlias => $controllerClass ){
            
            $simpleControllerAlias = $controllerAlias;
            if ( $controllerAlias == $controllerClass ){
                preg_match('/[a-zA-Z]+$/', $controllerClass, $matches);
                $simpleControllerAlias = $matches[0];
                $simpleControllerAlias        = preg_replace('/Controller$/', '', "{$moduleName}{$simpleControllerAlias}") ;
            }
            
            $routeAnnotationClazz = new \ReflectionClass('MkAnnotationRouter\Annotation\Route');
            $controllerClazz = new \ReflectionClass($controllerClass);
            /* @var $classRoute Route */
            
            $classRoutes        = array();
            foreach ($reader->getClassAnnotations($controllerClazz) as $classRoute){
                if ( $routeAnnotationClazz->isInstance($classRoute) ){
                    $classRoute->name       = empty($classRoute->name) ? $simpleControllerAlias : $classRoute->name;
                    $classRoute->defaults   = array_merge(array('controller'=> $controllerAlias, 'action'=> 'index' ), $classRoute->defaults);
                    $classRoute->route      = empty($classRoute->route) ? $simpleControllerAlias : $classRoute->route;
                    $classRouteArr          = $this->tranformRoute($classRoute);
                    
                    if( $classRoute->extends ){
                        $classRouteArr      = $this->extendsRoute($classRoute->extends , $classRouteArr);
                    }
                    $classRoutes[]          = [ 'annotation' => $classRoute , 'route' => $classRouteArr  ];
                    $routeToMergs[]         = $classRouteArr;
                }
            }
            
            
            do{
                foreach ($controllerClazz->getMethods( \ReflectionMethod::IS_PUBLIC) as $method){
                    if (preg_match('/Action$/', $method->getName())) {
                        /* @var $methodRoute Route */
                        foreach ($reader->getMethodAnnotations($method) as $methodRoute){
                            if ( $routeAnnotationClazz->isInstance($methodRoute) ){
                                
                                $actionName = $this->methodToActionName($method->getName());
                                $methodRoute->name = empty($methodRoute->name) ? $actionName : $methodRoute->name;
                                $methodRoute->route = empty($methodRoute->route) ? $actionName : $methodRoute->route;
                                
                                if (!empty($classRoutes) && !$methodRoute->extends) {
                                    
                                    $methodRoute->defaults = array_merge(array('action' => $actionName ), $methodRoute->defaults);
                                    $methodRouteArr = $this->tranformRoute($methodRoute);
                                    foreach ($classRoutes as $cr){
                                        $classRoute = $cr['annotation'];
                                        $extends = empty($classRoute->extends) ? $classRoute->name : $classRoute->extends . '/' . $classRoute->name;
                                        $methodRoutesToMerg[] = $this->extendsRoute($extends, $methodRouteArr);
                                    }
                                } else{
                                    $methodRoute->defaults = array_merge(array( 'controller' => $controllerAlias, 'action'=> $actionName ), $methodRoute->defaults);
                                    $methodRouteArr = $this->tranformRoute($methodRoute);
                                    if( $methodRoute->extends ){
                                        $methodRouteArr = $this->extendsRoute($methodRoute->extends , $methodRouteArr);
                                    }
                                    $methodRoutesToMerg[] = $methodRouteArr;
                                }
                            }
                        }
                    }
                }
                
                $controllerClazz = $controllerClazz->getParentClass();
                
            }while( $controllerClazz && $controllerClazz->getName() != 'Zend\Mvc\Controller\AbstractActionController' );
            
            $routeToMergs = array_merge($routeToMergs, $methodRoutesToMerg);
        }
        
        $finalAnnotationRoutes = call_user_func_array('array_replace_recursive', $routeToMergs);
        
        $config['router']['routes'] = ArrayUtils::merge($config['router']['routes'], $finalAnnotationRoutes);
        $configListener->setMergedConfig($config);
    }
    
    
    private function extendsRoute($extends, $childRoute){
        $result     = array();
        $target     = null;
        $extendArr  = explode('/', $extends );
        foreach ($extendArr as $extend){
            Assert::isTrue( !empty($extend) , $extends . ' is not valid parent route ');
            if ( !is_array($target) ){
                $target = &$result;
            }
            
            $target[$extend] = array();
            $target[$extend]['child_routes'] = array();
            $target = &$target[$extend]['child_routes'];
        }
        $target = $childRoute;
        return $result;
    }
    
    private function tranformRoute(Route $route){
        
        return array(
            $route->name => array(
                'type' => $route->type,
                'options' => array(
                    'route' => $route->route,
                    'constraints' => $route->constraints,
                    'defaults' => $route->defaults,
                ),
                'may_terminate' => $route->mayTerminate,
                'priority' => $route->priority,
                'child_routes' => array()
            )
        );
    }
    
    private function methodToActionName($methodName){
        $filter = new CamelCaseToDash();
        $methodName = preg_replace('/Action$/', '', $methodName);
        return strtolower($filter->filter($methodName));
    }
    
    
}

?>