<?php

namespace Soupmix\Adapters;
/*
MongoDB Adapter
*/

class MongoDB implements Base
{
    public $conn = null;

    private $dbName = null;

    public $db = null;

    public function __construct($config)
    {
        $this->dbName = $config['db_name'];
        $this->connect($config);
    }

    public function connect($config)
    {
        $this->conn = new \MongoDB\Client($config['connection_string'], $config['options']);
        $this->db = $this->conn->{$this->dbName};
    }

    public function create($collection, $config)
    {
        return $this->db->createCollection($collection);
    }

    public function drop($collection, $config)
    {
        return $this->db->dropCollection($collection);
    }

    public function truncate($collection, $config)
    {
        $this->db->dropCollection($collection);

        return $this->db->createCollection($collection);
    }

    public function createIndexes($collection, $indexes)
    {
        $collection = $this->db->selectCollection($collection);

        return $collection->createIndexes($indexes);
    }

    public function insert($collection, $values)
    {
        $collection = $this->db->selectCollection($collection);
        $result = $collection->insertOne($values);
        $docId = $result->getInsertedId();
        if (is_object($docId)) {
            return (string) $docId;
        }
        return null;
        
    }

    public function get($collection, $docId)
    {
        $collection = $this->db->selectCollection($collection);
        $filter = ['_id' => new \MongoDB\BSON\ObjectID($docId)];
        $options = [
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ];
        $result = $collection->findOne($filter, $options);
        if ($result!==null) {
            $result['id'] = (string) $result['_id'];
            unset($result['_id']);
        }

        return $result;
    }

    public function update($collection, $filter, $values)
    {
        $collection = $this->db->selectCollection($collection);
        $filter = self::buildFilter($filter)[0];
        $values_set = ['$set' => $values];
        if (isset($filter['id'])) {
            $filter['_id'] = new \MongoDB\BSON\ObjectID($filter['id']);
            unset($filter['id']);
        }
        $result = $collection->updateMany($filter, $values_set);

        return $result->getModifiedCount();
    }

    public function delete($collection, $filter)
    {
        $collection = $this->db->selectCollection($collection);
        $filter = self::buildFilter($filter)[0];
        if (isset($filter['id'])) {
            $filter['_id'] = new \MongoDB\BSON\ObjectID($filter['id']);
            unset($filter['id']);
        }
        $result = $collection->deleteMany($filter);

        return $result->getDeletedCount();
    }

    public function find($collection, $filters, $fields = null, $sort = null, $start = 0, $limit = 25, $debug = false)
    {
        $collection = $this->db->selectCollection($collection);
        if (isset($filters['id'])) {
            $filters['_id'] = new \MongoDB\BSON\ObjectID($filters['id']);
            unset($filters['id']);
        }
        $query_filters = [];
        if ($filters != null) {
            $query_filters = ['$and' => self::buildFilter($filters)];
        }
        $count = $collection->count($query_filters);
        if ($count > 0) {
            $results = [];
            $options = [
                'limit' => (int) $limit,
                'skip' => (int) $start,
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ];
            if ($fields!==null) {
                $projection = [];
                foreach ($fields as $field) {
                    if ($field=='id') {
                        $field = '_id';
                    }
                    $projection[$field] = true;
                }
                $options['projection'] = $projection;
            }
            if ($sort!==null) {
                foreach ($sort as $sort_key => $sort_dir) {
                    $sort[$sort_key] = ($sort_dir=='desc') ? -1 : 1;
                    if ($sort_key=='id') {
                        $sort['_id'] = $sort[$sort_key];
                        unset($sort['id']);
                    }
                }
                $options['sort'] = $sort;
            }
            $cursor = $collection->find($query_filters, $options);
            $iterator = new \IteratorIterator($cursor);
            $iterator->rewind();
            while ($doc = $iterator->current()) {
                if (isset($doc['_id'])) {
                    $doc['id'] = (string) $doc['_id'];
                    unset($doc['_id']);
                }
                $results[] = $doc;
                $iterator->next();
            }
            return ['total' => $count, 'data' => $results];
        }
        return ['total' => 0, 'data' => null];
    }

    public function query($query)
    {
        // reserved        
    }

    public static function buildFilter($filter)
    {
        $filters = [];
        foreach ($filter as $key => $value) {
            if (strpos($key, '__')!==false) {
                preg_match('/__(.*?)$/i', $key, $matches);
                $operator = $matches[1];
                switch ($operator) {
                    case '!in':
                        $operator = 'nin';
                        break;
                    case 'not':
                        $operator = 'ne';
                        break;
                    case 'wildcard':
                        $operator = 'regex';
                        $value = str_replace(array('?'), array('.'), $value);
                        break;
                    case 'prefix':
                        $operator = 'regex';
                        $value = $value.'*';
                        break;
                }
                $key = str_replace($matches[0], '', $key);
                $filters[] = [$key => ['$'.$operator => $value]];
            } elseif (strpos($key, '__')===false && is_array($value)) {
                $filters[]['$or'] = self::buildFilter($value);
            } else {
                $filters[][$key] = $value;
            }
        }

        return $filters;
    }
}
