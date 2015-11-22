<?php
namespace MkAnnotationRouter;

use Zend\ModuleManager\ModuleManager;
use Zend\ModuleManager\ModuleEvent;
use Doctrine\Common\Annotations\AnnotationReader;
use MkAnnotationRouter\Annotation\Route;
use Zend\Filter\Word\CamelCaseToDash;
use PhpCommonUtil\Util\Assert;

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
        foreach ( $controllers['invokables'] as $controllerAlias => $controllerClass ){
            
            $simpleControllerAlias = $controllerAlias;
            if ( $controllerAlias == $controllerClass ){
                preg_match('/[a-zA-Z]+$/', $controllerClass, $matches);
                $simpleControllerAlias = $matches[0];
                $simpleControllerAlias        = preg_replace('/Controller$/', '', "{$moduleName}{$simpleControllerAlias}") ;
            }
            

            $controllerClazz = new \ReflectionClass($controllerClass);
            /* @var $classRoute Route */
            $classRoute         = $reader->getClassAnnotation($controllerClazz, 'MkAnnotationRouter\Annotation\Route');
            $classRouteArr      = array();
            if($classRoute){
                $classRoute->name       = empty($classRoute->name) ? $simpleControllerAlias : $classRoute->name;
                $classRoute->defaults   = array_merge(array('controller'=> $controllerAlias, 'action'=> 'index' ), $classRoute->defaults);
                $classRoute->route      = empty($classRoute->route) ? $simpleControllerAlias : $classRoute->route;
                $classRouteArr          = array( $classRoute->name  =>  $this->tranformRoute($classRoute) );
            }
            
            $methods = array();
            $methodRoutesToMerg = array();
            do{
                $methods = array_merge($controllerClazz->getMethods( \ReflectionMethod::IS_PUBLIC));
                
                foreach ($methods as $method){
                    if (preg_match('/Action$/', $method->getName())) {
                        /* @var $methodRoute Route */
                        $methodRoute = $reader->getMethodAnnotation($method, 'MkAnnotationRouter\Annotation\Route');
                        
                        if($methodRoute)
                        {
                            $actionName = $this->methodToActionName($method->getName());
                            $methodRoute->name = empty($methodRoute->name) ? $actionName : $methodRoute->name;
                            $methodRoute->route = empty($methodRoute->route) ? $actionName : $methodRoute->route;
                            
                            if ($classRoute && ! $methodRoute->extends) {
                                $methodRoute->defaults = array_merge(array('action' => $actionName ), $methodRoute->defaults);
                                $methodRouteArr = array( $methodRoute->name => $this->tranformRoute($methodRoute));
                                $classRouteArr[$classRoute->name]['child_routes'] = array_merge($classRouteArr[$classRoute->name]['child_routes'], $methodRouteArr);
                            } else{
                                $methodRoute->defaults = array_merge(array( 'controller' => $controllerAlias, 'action'=> $actionName ), $methodRoute->defaults);
                                $methodRouteArr = array( $methodRoute->name  =>  $this->tranformRoute($methodRoute) );
                                if( $methodRoute->extends ){
                                    $methodRouteArr = $this->extendsRoute($methodRoute->extends , $methodRouteArr);
                                }
                                $methodRoutesToMerg[] = $methodRouteArr; 
                            }
                        }
                    }
                }
                
                $controllerClazz = $controllerClazz->getParentClass();
            }while( $controllerClazz && $controllerClazz->getName() != 'Zend\Mvc\Controller\AbstractActionController' );
            
            if($classRoute){
                if ( !empty($classRoute->extends ) ) {
                    $classRouteArr = $this->extendsRoute($classRoute->extends , $classRouteArr);
                }
                $routeToMergs[] = $classRouteArr;
            }
            $routeToMergs = array_merge($routeToMergs, $methodRoutesToMerg);
        }
        
        $finalAnnotationRoutes = call_user_func_array('array_merge_recursive', $routeToMergs);

        $config['router']['routes'] = array_merge_recursive($config['router']['routes'], $finalAnnotationRoutes);
        
        $configListener->setMergedConfig($config);
    }
    
    
    private function extendsRoute($extends, $childRoute){
        $result     = array();
        $target     = null;
        $extendArr  = explode('/', $extends );
        foreach ($extendArr as $extend){
            Assert::isTrue( !empty($extend) , $extends . ' is not valid parent route ');
            if ( null === $target ){
                $target = &$result;
            }else{
                $target = &$target['child_routes'];
            }
            
            $target[$extend] = array();
            $target[$extend]['child_routes'] = array();
        }
        $target[$extend]['child_routes'] = $childRoute;
        return $result;
    }
    
    private function tranformRoute(Route $route){
        
        return array(
            'type' => $route->type,
            'options' => array(
                'route' => $route->route,
                'constraints' => $route->constraints,
                'defaults' => $route->defaults,
            ),
            'may_terminate' => $route->mayTerminate,
            'priority' => $route->priority,
            'child_routes' => array()
            
        );
    }
    
    private function methodToActionName($methodName){
        $methodName = strtolower($methodName);
        $methodName = preg_replace('/action$/', '', $methodName);
        $filter = new CamelCaseToDash();
        return $filter->filter($methodName);
    }
    
    
}

?>