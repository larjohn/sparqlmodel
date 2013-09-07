<?php

//
// Copyright (c) 2013 Damien Legrand
// Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), 
// to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense,
// and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
// The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
// 
// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, 
// FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER 
// LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
//

namespace Legrand;

/**
 *
 * A model for Laravel 4, that can manage object through a graph database using SPARQL endpoint.
 *
 * @author Damien Legrand  < http://damienlegrand.com >
 */

use Illuminate\Support\Contracts\JsonableInterface;
use Illuminate\Support\Contracts\ArrayableInterface;
use Illuminate\Support\Facades\Config;
use Legrand\SPARQL;


class SPARQLModel implements JsonableInterface, ArrayableInterface
{

    public static $typeRegistry = array();
    protected static $mapping = [];
    protected static $multiMapping = [];
    protected static $baseURI = null;
    protected static $type = null;
    protected static $status = true;
    public $identifier = null;
    public $inStore = false;



    public static function init()
    {

        self::$typeRegistry[self::$type] = get_called_class();
    }

    public static function find($id)
    {
        $class = get_called_class();
        $m = new $class;

        if ((strlen($id) < 7 || substr($id, 0, 7) != 'http://') && static::$baseURI != null) $id = static::$baseURI . $id;

        $m->identifier = $id;
        $m->select();

        return $m;
    }

    public static function getConfig($setting)
    {
        return Config::get($setting);
    }

    public static function getMapping()
    {
        return static::$mapping;
    }
    public static function getMultiMapping()
    {
        return static::$multiMapping;
    }

    public static function lazyLoad($objects, $properties = array(),  $endpoint= null )
    {
        $class = get_called_class();
        $indexed_objects = array();
        foreach ($objects as $object) {
            $indexed_objects[$object->identifier] = $object;
        }

        $sparql = new SPARQL();
        if(isset($endpoint)) $sparql->baseUrl = $endpoint;
        else $sparql->baseUrl = $class::getConfig('sparqlmodel.endpoint');

        $sparql->select($class::getConfig('sparqlmodel.graph'))->distinct(true);
        $sparql->variable("?uri");
        if (!empty($properties)) {

            foreach ($properties as $property) {
                $propertyUri = "";
                foreach($class::$mapping as $propURI=>$prop){

                    if($prop ==$property){
                        $propertyUri = $propURI;
                        break;
                    }
                }
                if($propertyUri=="")
                    foreach($class::$multiMapping as $propURI=>$map){

                        if($map['property'] ==$property){
                            $propertyUri = $propURI;
                            break;
                        }
                    }
                if($propertyUri=="")continue;
                $sparql->variable("?".$property);
                $sparql->where('?uri', '<' . $propertyUri . '>', '?' . $property);
            }
        }
        $uris = array_keys($indexed_objects);
        if(count($uris)>0){
            foreach ($uris as &$uri) {

                $uri = '<' . $uri . '>';
            }


            $filter_range = '(' . implode(", ", $uris) . ')';
            $sparql->filter('?uri IN' . $filter_range);
        }
        else return;



        $data = $sparql->launch(false);
       //var_dump($sparql->sparql);

        if(!isset($data))return;
        $vals = array();
        foreach ($data['results']['bindings'] as $value) {

            foreach($value as $k=>$v){
                if(!isset($vals[$k]))$vals[$k] = array();
                if($k=="uri")continue;
                $found = false;
                foreach($class::$multiMapping as $propURI=>$map){

                    if($map['property'] ==$k){

                        $propertyClass = $map['mapping'];
                        $propertyObject = new $propertyClass();
                        $propertyObject->identifier = $v["value"];
                        $vals[$k][] = $propertyObject;
                       // var_dump($propertyObject);
                        $found = true;
                        break;
                    }
                }

                //if($found)continue;


                //$indexed_objects[$value["uri"]["value"]]->$k = $v["value"];
            }//var_dump($vals);
            $indexed_objects[$value["uri"]["value"]]->$k = $vals[$k] ;
            //var_dump( $indexed_objects[$value["uri"]["value"]]->$k);


        }




    }

    public static function listingFromQuery($sparql)
    {
        $class = get_called_class();
        $array = array();
        $data = $sparql->launch(false);

        foreach ($data['results']['bindings'] as $value) {
            if (!isset($array[$value["s"]["value"]]))
                $array[$value["s"]["value"]] = array();
            if (!isset($array[$value["s"]["value"]][$value["p"]["value"]]))
                $array[$value["s"]["value"]][$value["p"]["value"]] = array();
            $array[$value["s"]["value"]][$value["p"]["value"]][] = $value["o"]["value"];

        }



        $objects = array();
        foreach ($array as $id => $rdfo) {
            $thisClass = get_called_class();
            $newElement = new $thisClass();
            $newElement->identifier = $id;
            foreach ($rdfo as $property => $value) {

                if (isset($thisClass::$mapping[$property])) {
                    $objectProperty = $thisClass::$mapping[$property];
                    $newElement->$objectProperty = $value;
                    continue;
                } elseif (isset($thisClass::$multiMapping[$property]['property'])) {
                    foreach ($value as $val) {
                        $objectProperty = $thisClass::$multiMapping[$property]['property'];
                        $propertyClass = $thisClass::$multiMapping[$property]['mapping'];
                        if (isset($objects[$val])) {
                            $propertyObject = $objects[$val];
                        } else {

                            $propertyObject = new $propertyClass();
                            $propertyObject->identifier = $val;
                            $objects[$val] = $propertyObject;
                        }
                        if (!isset($newElement->$objectProperty))
                            $newElement->$objectProperty = array();

                        $newElement->{$objectProperty}[] = $propertyObject;
                    }

                } else continue;
            }
            $objects[$id] = $newElement;

        }
        //var_dump($sparql->sparql);die;
        return $objects;
    }

    public function processLine($value)
    {
        if (!isset($value['uri']['value']) || $value['uri']['value'] != $this->identifier) return;

        foreach ($this::$mapping as $uri => $property) {
            if (isset($value[$property])) {
                $this->$property = $value[$property]['value'];
            }
        }

        $this->inStore = true;
    }

    public function add($data)
    {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
    }

    public function listing($forProperty = false)
    {
        $class = get_called_class();
        if ($this->identifier == null || $this->identifier == "") throw new Exception('The identifier has no value');

        foreach ($this::$multiMapping as $k => $v) {

            if ($forProperty != false) {
                if ($v['property'] != $forProperty) continue;
            }

            $array = [];

            $sparql = new SPARQL();
            $sparql->baseUrl = $class::getConfig('sparqlmodel.endpoint');

            $elementMapping = call_user_func([$v['mapping'], 'getMapping']);
            if (isset($v["inverse"]) && $v["inverse"]) {
                $sparql->select($class::getConfig('sparqlmodel.graph'))->distinct(true)
                    ->where('?uri', "<$k>", '<' . $this->identifier . '>');
            } else {
                $sparql->select($class::getConfig('sparqlmodel.graph'))->distinct(true)
                    ->where('<' . $this->identifier . '>', "<$k>", '?uri');
            }


            foreach ($elementMapping as $uri => $p) {

                $sparql->optionalWhere('?uri', '<' . $uri . '>', "?$p");
            }

            if (isset($v['order']) && count($v['order']) == 2) $sparql->orderBy($v['order'][0] . "(?" . $v['order'][1] . ")");
            if (isset($v['limit']) && is_numeric($v['limit'])) $sparql->limit($v['limit']);

            $data = $sparql->launch();

            foreach ($data['results']['bindings'] as $value) {
                $found = false;
                foreach ($array as $element) {
                    if ($element->identifier == $value['uri']['value']) {
                        $found = true;
                        $element->processLine($value);
                        break;
                    }
                }

                if (!$found) {
                    $newElement = new $v['mapping']();
                    $newElement->identifier = $value['uri']['value'];
                    $newElement->processLine($value);
                    $array[] = $newElement;
                }
            }


            if(isset($this->$v['property'])){

                $this->$v['property'] = array_merge($this->$v['property'],$array);
            }
            else
            $this->$v['property'] = $array;

            if ($forProperty != false) break;
        }
        return $this;
    }

    public function save($moreData = [])
    {
        $class = get_called_class();
        if ($this->inStore) {
            //we update here so we delete triples

            $filter = "";
            foreach ($this::$mapping as $uri => $property) {
                if (isset($this->$property) && ($uri != $class::getConfig('sparqlmodel.created') || $uri != $class::getConfig('sparqlmodel.updated'))) {
                    if ($filter != "") $filter .= " || ";
                    $filter .= "?x = <$uri>";
                }
            }

            foreach ($moreData as $uri => $value) {
                if ($filter != "") $filter .= " || ";
                $filter .= "?x = <$uri>";
            }

            $filter .= " || ?x = <" . $class::getConfig('sparqlmodel.updated') . ">";

            $sparqlD = new SPARQL();
            $sparqlD->baseUrl = $class::getConfig('sparqlmodel.endpoint');

            $sparqlD->delete($class::getConfig('sparqlmodel.graph'), '<' . $this->identifier . '> ?x ?y')
                ->where('<' . $this->identifier . '>', '?x', '?y')
                ->filter($filter)
                ->launch();
        } else {
            $this->identifier = $this->generateID();
            $this->select();
            if ($this->inStore) return;
        }

        $this->identifier = $this->generateID();

        $sparql = new SPARQL();
        $sparql->baseUrl = $class::getConfig('sparqlmodel.endpoint');

        $sparql->insert($class::getConfig('sparqlmodel.graph'));

        foreach ($this::$mapping as $uri => $property) {
            if (isset($this->$property)) {
                $p = (is_string($this->$property)) ? "'" . $this->$property . "'" : $this->$property;
                $sparql->where('<' . $this->identifier . '>', '<' . $uri . '>', $p);
            }
        }

        foreach ($moreData as $uri => $value) {
            $p = (is_string($value)) ? "'" . $value . "'" : $value;
            $sparql->where('<' . $this->identifier . '>', '<' . $uri . '>', $p);
        }

        if ($this::$type != null) $sparql->where('<' . $this->identifier . '>', '<http://www.w3.org/1999/02/22-rdf-syntax-ns#type>', '<' . $this::$type . '>');

        if ($this::$status) $sparql->where('<' . $this->identifier . '>', '<' . $class::getConfig('sparqlmodel.status') . '>', 1);

        $date = date('Y-m-d H:i:s', time());

        if (!$this->inStore) $sparql->where('<' . $this->identifier . '>', '<' . $class::getConfig('sparqlmodel.created') . '>', "'" . $date . "'");
        $sparql->where('<' . $this->identifier . '>', '<' . $class::getConfig('sparqlmodel.updated') . '>', "'" . $date . "'");

        $data = $sparql->launch();

        $this->inStore = true;
    }

    public function generateID()
    {
        throw new Exception("generatedID method has to be overrided");
    }

    public function select()
    {
        if ($this->identifier == null || $this->identifier == "") throw new Exception('The identifier has no value');
        $class = get_called_class();
        $sparql = new SPARQL();
        $sparql->baseUrl = $class::getConfig('sparqlmodel.endpoint');

        $filter = "?uri = <" . $this->identifier . "> && (";
        $first = true;
        foreach ($this::$mapping as $k => $v) {
            if ($first) $first = false;
            else $filter .= " || ";

            $filter .= "?property = <$k>";
        }

        $filter .= ")";

        $sparql->select($class::getConfig('sparqlmodel.graph'))->where('?uri', '?property', '?value');

        if ($this::$status) $sparql->where('?uri', '<' . $class::getConfig('sparqlmodel.status') . '>', 1);

        $data = $sparql->filter($filter)->launch();

        if (!isset($data['results']) || !isset($data['results']['bindings'])) {
            return;
        }

        foreach ($data['results']['bindings'] as $value) {
            $this->process($value);
        }
    }

    public function process($value)
    {
        if (!isset($value['uri']['value']) || $value['uri']['value'] != $this->identifier) return;

        $property = '';
        if (isset($this::$mapping[$value['property']['value']])) $property = $this::$mapping[$value['property']['value']];
        //else continue;

        $this->$property = $value['value']['value'];
        $this->inStore = true;
    }

    public function delete($logicDelete = false)
    {
        $class = get_called_class();
        if (!$this->inStore) return;

        $sparql = new SPARQL();
        $sparql->baseUrl = $class::getConfig('sparqlmodel.endpoint');

        if (!$logicDelete) {
            //Real delete
            $sparql->delete($class::getConfig('sparqlmodel.graph'), '<' . $this->identifier . '> ?x ?y')
                ->where('<' . $this->identifier . '>', '?x', '?y');
        } else {
            //Logic Delete
            $sparql->delete($class::getConfig('sparqlmodel.graph'), '<' . $this->identifier . '> <' . $class::getConfig('sparqlmodel.status') . '> ?y')
                ->where('<' . $this->identifier . '>', '<' . $class::getConfig('sparqlmodel.status') . '>', '?y');
        }

        $data = $sparql->launch();

        if ($logicDelete) {
            $sparql2 = new SPARQL();
            $sparql2->baseUrl = $class::getConfig('sparqlmodel.endpoint');
            $sparql2->insert($class::getConfig('sparqlmodel.graph'))
                ->where('<' . $this->identifier . '>', '<' . $class::getConfig('sparqlmodel.status') . '>', 2)->launch();
        }

        $this->inStore = false;
    }

    public function link($object)
    {
        $class = get_called_class();
        if ($this::$multiMapping == null) return;

        $c = get_class($object);
        $multimapping = null;
        $map = null;

        foreach ($this::$multiMapping as $key => $value) {
            if (isset($value['mapping']) && $value['mapping'] == $c) {
                $map = $key;
                $multimapping = $value;
                break;
            }
        }

        if ($map == null) return;

        $sparql = new SPARQL();
        $sparql->baseUrl = $class::getConfig('sparqlmodel.endpoint');
        if (isset($value["inverse"]) && $value["inverse"] == true) {
            $sparql->insert($class::getConfig('sparqlmodel.graph'))
                ->where('<' . $object->identifier . '>', '<' . $map . '>', '<' . $this->identifier . '>')
                ->launch();
        } else {
            $sparql->insert($class::getConfig('sparqlmodel.graph'))
                ->where('<' . $this->identifier . '>', '<' . $map . '>', '<' . $object->identifier . '>')
                ->launch();
        }

    }

    public function exist()
    {
        return $this->inStore;
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param  int $options
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Get the instance as an array.
     *
     * @return array
     */
    public function toArray($expand = false)
    {
        $object = [];
        $object['id'] = $this->identifier;
        if ($expand && !$this->inStore) $this->select();
        foreach ($this::$mapping as $key => $value) {

            if (isset($this->$value)) $object[$value] = $this->$value;


        }

        foreach ($this::$multiMapping as $key => $value) {

            $p = $value['property'];
            if (isset($this->$p) && is_array($this->$p) && count($this->$p) > 0) {
                $element = $this->$p;
                $element = $element[0];

                if (!is_object($element)) {
                    $object[$p] = $this->$p;
                    break;
                }

                $implements = class_implements(get_class($element));
                if (in_array('Illuminate\Support\Contracts\ArrayableInterface', $implements)) {
                    $object[$p] = [];
                    foreach ($this->$p as $o) {
                        if($expand){

                            $o->listing();

                        }
                        $object[$p][] = $o->toArray($expand);


                    }
                } else {
                    $object[$p] = $this->$p;
                }
            } elseif (isset($this->$p)){
                if($p == "category")
                $object[$p] = $this->$p;
            }
        }

        return $object;
    }
}