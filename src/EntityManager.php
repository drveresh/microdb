<?php

namespace MicroDB;
use Engine\Functions\Object;

/**
 * Description of EntityManager
 *
 * @author Sébastien Dugene
 */
class EntityManager
{
	private static $_instance = null;
	
	protected $joinedProperties = null;
    protected $reflectionClass = null;
    protected $properties = null;
    protected $mapping = null;
    protected $class = null;
	
	private $conf = [];
	private $entity = null;
	private $folder = null;
	private $database;

    /**
     * @return void
     */
    private function __construct($params) {
    	$this->useParams($params);
    	$this->database = new Database();
    	$this->database->secure($this->conf);
    }

    /**
     * @return EntityManager
     */
    public static function getManager($params = [])
    {
        if(is_null(self::$_instance)) {
            self::$_instance = new EntityManager($params);
        }
        return self::$_instance;
    }
    
    /// METHODS
    public function entity($entity)
    {
    	$this->entity = $entity;
    	if (is_object($entity)) {
	    	$this->class = get_class($this->entity);
	    	$this->getClassAnnotations();
	    	$this->database->setPath($this->folder.$this->entity->getClassName());
    	} else {
    		$this->database->setPath($this->folder.$entity);
    	}
    	return $this;
    }

    /**
     * @param int|array $id|$criteria
     * @param array|int $join|$limit
     * @param int|array $limit|$order
     * @param array $order|$group
     * @param array $group
     * @return array
     */
    public function find()
    {
        /**
         *  $args[0] : $criteria
         *  $args[1] : $join
         *  $args[2] : $limit
         *  $args[3] : $order
         *  $args[4] : $group
         */
        $args = $this->getArgs(func_get_args());
        
        if (is_numeric($args[0])) {
            return $this->findById($args[0]);
        }
        
        if ($args[0] == '*') {
        	return $this->findByCriteria();
        }
        
        if (is_array($args[1]) && !empty($args[1])) {
            return $this->findWithJoin($args[0], $args[1], $args[2], $args[3], $args[4]);
        }
        
        if (is_array($args[0])) {
            return $this->findByCriteria($args[0], $args[1], $args[2], $args[3]);
        }
    }
    
    private function findById($id)
    {
    	$result = $this->database->load($id);
    	return Object::fillWithJSon($this->entity, json_encode($result));
    }

    /**
     * @param array $criteria
     * @param bool|false $maxLine
     * @param bool|false $order
     * @param bool|false $group
     * @return array
     */
    private function findByCriteria($criteria = [], $maxLine = false, $order = false, $group = false)
    {
    	$results = [];
    	$array = $this->database->find($criteria);
    	foreach ($array as $key => $value) {
    		if ($key == $maxLine) {
    			break;
    		}
    		$results[] = Object::fillWithJSon($this->entity, json_encode($value));
    	}
    	return $results;
    }

    /**
     * @param array $criteria
     * @param array $join
     * @param bool|false $maxLine
     * @param bool|false $order
     * @param bool|false $group
     * @return array
     */
    private function findWithJoin($criteria = [], $join = [], $maxLine = false, $order = false, $group = false)
    {
        $results = [];
        $i = 0;
    	$array = $this->database->find($criteria);
    	foreach ($array as $key => $value) {
    		if ($key == $maxLine) {
    			break;
    		}
    		$results[$i] = Object::fillWithJSon($this->entity, json_encode($value));
    		$results[$i] = $this->join($join, $results[$i]);
    		$i++;
    	}
    	return $results;
    }

    /**
     * @param $args
     * @param int $max
     * @return mixed
     */
    private function getArgs($args, $max = 5)
    {
        for($j = 0 ; $j < $max ; $j++) {
            if (!array_key_exists($j, $args) && $j < 2) {
                $args[$j] = [];
            } elseif (!array_key_exists($j, $args)) {
                $args[$j] = false;
            }
        }
        return $args;
    }

    /**
     * @return void
     */
    private function getClassAnnotations()
    {
        $this->mapping = Mapping::getReader($this->class);
        $this->properties = $this->mapping->getPropertiesMapping();
        $this->joinedProperties = $this->mapping->getPropertiesMapping('Joined');
    }
    
    public function insert($input)
    {
    	return $this->database->copy($input);
    }
    
    private function join($join, $result)
    {
    	foreach ($join as $method => $joinArray) {
            $className = key($joinArray);
            $joinCriteria = $this->joinCriteria($joinArray[$className], $className, $result);
            $this->database->setPath($this->folder.ucfirst($className));
            $resultJoin = $this->database->find($joinCriteria);
            
            if (count($resultJoin) == 1) {
            	$resultJoin = $resultJoin[key($resultJoin)]; 
            }
            
            foreach($this->joinedProperties as $property => $needed) {
            	if (preg_match('/'.$className.'_([a-z_-]*)/', $needed, $infos) && array_key_exists($infos[1], $resultJoin)) {
            		$result->$property = $resultJoin[$infos[1]];
            	}
            }
        }
        return $result;
    }

    /**
     * @param $criteria
     * @param $table
     * @return string
     */
    private function joinCriteria($criteria, $table, $result)
    {
    	$joinCriteria = [];
    	foreach ($criteria as $boolean => $column) {
    		if(!is_array($column)) {
                $joinMapping = $this->mapping->getPropertieJoinColumn($column, $table);
            }
            
            foreach ($joinMapping as $key => $value) {
            	preg_match('/^@([a-zA-Z_-]*)\.@([a-zA-Z_-]*)/', $key, $matchesKey);
            	preg_match('/^@([a-zA-Z_-]*)\.@([a-zA-Z_-]*)/', $value, $matchesValue);
            	if ($this->entity->getClassName() == ucfirst($matchesKey[1])) {
            		$joinCriteria[$matchesValue[2]] = $this->entity->$matchesKey[2];
            	}
            }
    	}
    	
    	return $joinCriteria; 
    }
    
    public function delete($id)
    {
    	return $this->database->delete($id);
    }
    
    private function useParams($params)
    {
    	foreach($params as $key => $value) {
    		switch($key) {
    			case 'folder':
    				$this->folder = $value.'/library/data/';
    				break;
    			case 'identification':
    			case 'initialisation':
    				$this->conf[$key] = $value;
    				break;
    		}
    	}
    }
}