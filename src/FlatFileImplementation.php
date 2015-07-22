<?php

namespace Mockapi\ResourceProvider;

use \Exception;
use \Rhumsaa\Uuid\Uuid;

use \Mockapi\Validate\Validate;

use \Mockapi\Interfaces\ResourceProviderInterface;
use \Mockapi\Interfaces\SerializerInterface;
use \Mockapi\Interfaces\HttpInterface;

class FlatFileImplementation implements ResourceProviderInterface, HttpInterface
{
    protected $now;
    protected $root;
    protected $type;
    protected $endpoint = '/';
    protected $serializer;
    protected $createEmptyObject = false;

    protected $total = 0;

    protected static $microtimeTimestamps = true;

    protected static $unique = ['slug'];
    protected static $immutable = ['type'];

    public function __construct(array $args)
    {
        $required = ['root', 'type', 'serializer'];

        Validate::requireAttributes($required, $args, 'Flat file resource arguments');

        foreach ($args as $k => $v) {
            switch ($k) {
                case 'root':
                    Validate::isWritableDir($v, "`{$k}` argument");
                    $this->{$k} = '/'.trim($v, '/');
                break;

                case 'type':
                    Validate::isNonEmptyString($v, "`{$k}` argument");
                    Validate::isPlural($v, "`{$k}` argument");

                    $this->{$k} = $v;
                break;

                case 'serializer':
                    if (Validate::isNonEmptyString($v,false)) {
                        if (class_exists($v)) {
                            $v = new $v;
                        }
                    }

                    if (!$v instanceof SerializerInterface) {
                        throw new Exception('Serializer must implement `SerializerInterface`.');
                    }

                    $this->{$k} = $v;
                break;

                case 'createEmptyObject':
                    $this->{$k} = !!$v;
                break;

                case 'endpoint':
                    Validate::isUrl($v);

                    $this->{$k} = rtrim($v, '/');
                break;
            }
        }

        $this->now = $this->time();
    }

    // HELPERS

    // Date/Time Helpers -------------------------------------------------------

    /**
     * Converts UNIX timestamp into normalized timestamp.
     *
     * @see ISO-8601 Time Format
     *
     * @param $timestamp Omitting parameter returns current time timestamp
     *
     */
    protected function timestamp($timestamp = null)
    {
        if ($timestamp === null) {
            $timestamp = $this->now;
        }

        if (!!self::$microtimeTimestamps) {
            $timestamp = implode('.', array_map(function($v) {
                return str_pad($v, 3, '0', STR_PAD_RIGHT);
            }, explode('.', $timestamp)));

            return substr_replace(date('c', $timestamp), '.'.array_pop(explode('.', $timestamp)), 19, 0);
        }

        return date('c', $timestamp);
    }

    protected function time()
    {
        if (!!self::$microtimeTimestamps) {
            return array_sum(explode(' ', substr_replace(microtime(), '', 5, 5)));

            // Whish this would work as this operates on string to maintain precision but still cannot return float with greater precision
            return implode('', array_reverse(explode(' ', rtrim(substr_replace(microtime(), '', 5, 5), 0))));
        }

        return time();
    }

    /**
     * Microtime avare strtotime method
     *
     * @see strtotime()
     * @param string $t Time string
     * @return float
     *
     */
    protected function strtotime($t)
    {
        $time = strtotime($t);

        if (preg_match('/T\d{2}:\d{2}:\d{2}(\.\d+)/', $t, $matches)) {
            $time += $matches[1];
        }

        return (float) $time;
    }

    /**
     * Function to generate a slug (a normalized URL sanitized string)
     *
     * Turns 'Any impressive headline' to 'any-impressive-headline'
     *
     * @param   string $str     String to use to generate slug
     * @return  string          Returns slug
     * @author                  internet
     * @license                 public domain
     *
     */
    function generateSlug($str)
    {
        setlocale(LC_ALL, 'en_US.UTF8');
        $r = '';
        $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
        for ($i = 0; $i < strlen($str); $i++) {
            $ch1 = $str[$i];
            $ch2 = mb_substr($str, $i, 1);
            $r .= $ch1 == '?' ? $ch2 : $ch1;
        }
        $str = str_replace(
            array('&auml;', '&ouml;', '&uuml;', '&szlg', '&amp;', ' & ', '&', ' - ', '/', ' / ', ' ', '='),
            array('ae', 'oe', 'ue', 'ss', '-and-', '-and-', '-and-', '-', '-', '-', '-', '-'),
            strtolower($r)
        );
        $chars = "abcdefghijklmnopqrstuvwxyz0123456789_-";
        $replace = '';
        for ($i=0; $i<strlen($str); $i++) {
            if (false !== strpos($chars, $str{$i})) {
                $replace .= $str{$i};
            }
        }
        return $replace;
    }

    // Low API (do not use in public functions)

    protected function path($id, $attr = null)
    {
        return "{$this->root}/{$this->type}/{$id}" . ($attr === null ? '' : "/{$attr}" );
    }

    // Lower API

    protected function readAttrByPath($path)
    {
        return $this->serializer->decode(file_get_contents($path));
    }

    protected function writeAttrByPath($path, $data)
    {
        return file_put_contents($path, $this->serializer->encode($data));
    }

    // Higher API

    protected function readAttr($id, $attr, $cast = true)
    {
        if ($attr === 'self') {
            return $this->self($id);
        }

        $v = $this->readAttrByPath($this->path($id, $attr));

        if ($cast && ($attr === 'created' || $attr === 'updated')) {
            return $this->timestamp($v);
        }

        return $v;
    }

    protected function writeAttr($id, $attr, $data, $assert = true)
    {
        if ($attr === 'slug') {
            Validate::isNonEmptyString($data, 'slug');

            $data = $this->generateSlug($data);
        }

        // Check uniqes
        if ($assert && in_array($attr, self::$unique)) {
            $this->assertUnique($attr, $data);
        }

        $ok = $this->writeAttrByPath($this->path($id, $attr), $data);

        if (!$ok) {
            throw new Exception("Failed to write {$attr} attribute for {$this->type} resource with {$id} id.", self::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    protected function write($id, $data)
    {
        if (isset($data->name) && !isset($data->slug)) {
            $data->slug = $data->name;
        }

        if (isset($data->slug)) {
            $data->slug = $this->generateSlug($data->slug);
        }

        if (!$this->exists($id)) {
            foreach (array_keys((array) $data) as $attr) {
                // Check uniqes
                if (in_array($attr, self::$unique)) {
                    $this->assertUnique($attr, $data->{$attr});
                }
            }

            mkdir($this->path($id), 0755, true);
        }

        if (!$this->exists($id)) {
            throw new Exception("Failed to create new {$this->type} resource with {$id} id.", self::HTTP_INTERNAL_SERVER_ERROR);
        }

        foreach (array_keys((array) $data) as $attr) {
            // Cast as array for all plural keys
            if (Validate::isPlural($attr, false)) {
                $data->{$attr} = (array) $data->{$attr};
            }

            $this->writeAttr($id, $attr, $data->{$attr}, false);
        }
    }

    protected function unlinkAttr($id, $attr)
    {
        $ok = unlink($this->path($id, $attr));

        if (!$ok) {
            throw new Exception("Failed to delete {$attr} attribute for {$this->type} resource with {$id} id.", self::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    protected function unlink($id)
    {
        foreach ($this->attributes($id) as &$attr) {
            $this->unlinkAttr($id, $attr);
        }

        $ok = rmdir($this->path($id));

        if (!$ok) {
            throw new Exception("Failed to delete {$this->type} resource with {$id} id.", self::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    protected function sort(array $a)
    {
        sort($a);
        return $a;
    }

    protected function attributes($id)
    {
        return $this->sort(array_merge(['self'], array_map(function($v) {
            return basename($v);
        }, glob($this->path($id, '*'), GLOB_NOSORT))));
    }

    protected function assertIDExists($id)
    {
        if (!$this->exists($id)) {
            throw new Exception("No {$this->type} found", self::HTTP_NOT_FOUND);
        }

        return true;
    }

    protected function assertUnique($attr, $value)
    {
        if (!empty($this->find([$attr => $value], 0, 1))) {
            throw new Exception("Resource {$this->type} with `{$attr}` attribute of `{$value}` already exists", self::HTTP_CONFLICT);
        }

        return true;
    }

    protected function assertIDExistsNot($id)
    {
        if ($this->exists($id)) {
            throw new Exception("Resource {$this->type} already exists", self::HTTP_CONFLICT);
        }

        return true;
    }

    protected function uuid()
    {
        $uuid4 = Uuid::uuid4();
        return $uuid4->toString();
    }

    /* string */ public function self($id)
    {
        return $this->endpoint."/{$this->type}/{$id}";
    }

    // RETRIEVE METHODS

    /* bool */ public function exists($id, $throw = false)
    {
        if ($throw) {
            return $this->assertIDExists($id);
        }

        return file_exists($this->path($id));
    }

    /* object $resource */ public function get($id, /* include these */ array $attrs = [])
    {
        $this->assertIDExists($id);

        if (empty($attrs)) {
            $attrs = $this->attributes($id);
        }

        $data = new \StdClass;

        foreach ($attrs as &$attr) {
            $data->{$attr} = $this->readAttr($id, $attr);
        }

        return $data;
    }

    /* mixed $value */ public function getAttr($id, $attr)
    {
        $this->assertIDExists($id);

        return $this->readAttr($id, $attr);
    }

    /* array [object $resources] */ public function fetch(array $ids, /* include these */ array $attrs = [])
    {
        $objects = [];

        foreach ($ids as &$id) {
            $objects[] = $this->get($id, $attrs);
        }

        return $objects;
    }

    /* array [$listOfIds] */ protected function all(array $sorts)
    {
        $all = array_map(function($p) {
            return basename($p);
        }, glob($this->path('*'), GLOB_NOSORT));

        $sortArgs = [];

        foreach ($sorts as $by => $how) {
            $values = [];
            $sortArgs[] = &$values;

            foreach ($all as $id) {
                $values[] = $this->readAttr($id, $by, false);
            }

            if ($how === true || strtolower($how) === 'asc' || $how === '+') {
                $sortArgs[] = SORT_ASC;
            } else {
                $sortArgs[] = SORT_DESC;
            }
        }

        $sortArgs[] = &$all;

//        echo json_encode($all, JSON_PRETTY_PRINT);echo "\n\n";
//        echo json_encode($sortArgs, JSON_PRETTY_PRINT);echo "\n\n";

        call_user_func_array('array_multisort', $sortArgs);

//        echo json_encode($all, JSON_PRETTY_PRINT);echo "\n\n";

        return $all;
    }

    /* array [$listOfIds] */ public function find(array $where = [], $limit = null, $offset = 0, $sorts = null)
    {
        if (empty($sorts)) {
            $sorts = [
                'created' => '-' // descending: '-', 'desc', false
            ];
        } else {
            if (is_string($sorts)) {
                $_sorts = explode(',', $sorts);
                $sorts = [];

                foreach($_sorts as $k => $v) {
                    var_dump($v);

                    if (!empty($v)) {
                        $how = '+';
                        if ($v[0] === '+' || $v[0] === '-') {
                            $how = $v[0] === '-' ? 'desc' : 'asc';
                        }

                        $v = trim($v, '+- ');

                        $sorts[$v] = $how;
                    }
                }
            }
        }

//        var_dump($sorts);

        if (empty($where)) {
            $all = $this->all($sorts);
            $this->found = count($all);

            return array_map(function($p) {
                return basename($p);
            }, array_slice($all, $offset, $limit));
        }

        $listOfIds = null;

        foreach ($where as $attr => $value) {
            $partialResults = array_filter(array_map(function($v) use ($attr, $value) {
                $id = basename(dirname($v));
                $plural = Validate::isPlural($attr, false);

//                $v = $this->getAttr($id, $attr);

//                echo "\n{$id}: {$v}\n";

                if ($plural) {
                    if (in_array($value, $this->getAttr($id, $attr))) {
                        return $id;
                    }
                } else{
                    if ($this->getAttr($id, $attr) === $value) {
                        return $id;
                    }
                }

                return false;
            }, glob($this->path('*', $attr), GLOB_NOSORT)));

            if ($listOfIds === null) {
                $listOfIds = array_values($partialResults);
            } else {
                $listOfIds = array_intersect($listOfIds, array_values($partialResults));
            }
        }

        $this->found = count($listOfIds);

        return array_slice($listOfIds, $offset, $limit);
    }

    /* int $found */ public function found()
    {
        return $this->found;
    }

    /* int $found */ public function endpoint()
    {
        return "{$this->endpoint}/{$this->type}";
    }

    // CREATE METHODS

    /* object $payload */ public function add($id = null, $payload = null)
    {
        // Allow use 1 argument as full object
        if ($payload === null && is_object($id)) {
            $payload = $id;

            $id = isset($payload->id) ? $payload->id : null;
        }

        // Validate payload
        Validate::isObject($payload, 'Payload');

        if (!isset($payload->updated)) {
            $payload->updated = $this->now;
        } else {
            $payload->updated = $this->strtotime($payload->updated);
        }

        if (!isset($payload->created)) {
            $payload->created = $this->now;
        } else {
            $payload->created = $this->strtotime($payload->created);
        }

        if (isset($payload->type) && $payload->type !== $this->type) {
            throw new Exception('Payload must be the same type as resource.', 400);
        }

        if (!isset($payload->type)) {
            $payload->type = $this->type;
        }

        // If ID not set, use the object->id
        if (!isset($id) && isset($payload->id)) {
            $id = $payload->id;
        }

        // If ID still not set, generate new
        if ($id === null) {
            $id = $this->uuid();
        }

        // Set ID and overwrite (creates a new copy if ID arg is provided)
        $payload->id = $id;

        // Check UUID is available
        $this->assertIDExistsNot($id);

        $this->write($id, $payload);

        $payload->created = $this->timestamp($payload->created);
        $payload->updated = $this->timestamp($payload->updated);

        return $payload;
    }

    /* mixed $payload */ public function addAttr($id, $attr, $payload = null)
    {
        $this->assertIDExists($id);

        if (!Validate::isPlural($attr, false)) {
            throw new Exception('To POST new value in attribute, attribute must be plural. For update use PUT or PATCH methods.');
        }

        $payload = array_merge((array) $this->readAttr($id, $attr), (array) $payload);

        $this->writeAttr($id, $attr, $payload);

        return $payload;
    }

    // UPDATE METHODS

    public function touch($id)
    {
        $this->assertIDExists($id);
        $this->writeAttr($id, 'updated', $this->now);

        return $payload;
    }

    public function update($id = null, $payload = null)
    {
        // Allow use 1 argument as full object
        if ($payload === null && is_object($id)) {
            $payload = $id;
            $id = $payload->id;
        }

        if (!isset($payload->updated)) {
            $payload->updated = $this->now;
        } else {
            $payload->updated = $this->strtotime($payload->updated);
        }

        // Validate payload
        Validate::isObject($payload, 'Payload');

        // If ID not set, use the object->id
        if (!isset($id) && isset($payload->id)) {
            $id = $payload->id;
        }

        // If ID still not set, generate new
        if ($id === null) {
            throw new Exception("To update resource {$this->resource} `id` attribute mus be set", self::HTTP_BAD_REQUEST);
        }

        // Set ID and overwrite (creates a new copy if ID arg is provided)
        $payload->id = $id;

        // Check UUID is available
        $this->assertIDExists($id);

        foreach (array_keys((array) $payload) as $attr) {
            // Skip immutables
            if (in_array($attr, self::$immutable)) {
                unset($payload->{$attr});
            }
        }

        $this->write($id, $payload);

        return $payload;
    }

    public function updateAttr($id, $attr, $payload)
    {
        $this->assertIDExists($id);

        // Skip immutables
        if (in_array($attr, self::$immutable)) {
            return;
        }

        $this->writeAttr($id, $attr, $payload);
        $this->touch($id);

        return $payload;
    }


    // DELETE METHODS

    public function delete($ids)
    {
        foreach ((array) $ids as $id) {
            $this->assertIDExists($id);
            $this->unlink($id);
        }
    }

    public function deleteAttr($ids, $attrs)
    {
        foreach ((array) $ids as $id) {
            foreach ((array) $attrs as $attr) {
                $this->assertIDExists($id);
                $this->unlinkAttr($id, $attr);
            }
        }
    }

    public function removeAttrValue($id, $attr, $values)
    {
        $this->assertIDExists($id);

        return array_filter($this->getAttr($id, $attr), function($v) use ($values) {
            return !in_array($v, (array) $values);
        });
    }
}
