<?php

namespace Mockapi\ResourceProvider\FlatFileImplementation;

use \Symfony\Component\Yaml\Parser;
use \Symfony\Component\Yaml\Yaml;

use \Mockapi\Interfaces\SerializerInterface;

class YamlSerializer implements SerializerInterface
{
    static $parser;

    public function encode($v)
    {
        return Yaml::dump(json_decode(json_encode($v), true), 4, 4, true);
    }

    public function decode($v)
    {
        // Bind lazily
        if (!static::$parser) {
            static::$parser = new Parser;
        }

        return json_decode(json_encode(static::$parser->parse($v, false, false, true)));
    }
}
