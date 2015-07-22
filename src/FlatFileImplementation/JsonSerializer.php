<?php

namespace Mockapi\ResourceProvider\FlatFileImplementation;

use \Mockapi\Interfaces\SerializerInterface;

class JsonSerializer implements SerializerInterface
{
    public function encode($v)
    {
        return json_encode($v);
    }

    public function decode($v)
    {
        return json_decode($v);
    }
}
