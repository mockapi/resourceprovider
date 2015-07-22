<?php

require_once '../vendor/autoload.php';

header('Content-Type: text/plain');

//try {
//    $resource = new \Mockapi\ResourceProvider\FlatFileImplementation();
//} catch (Exception $e) {
//    echo "OK - Resource should be instantiated with type argument\n";
//}

try {
    $resource = new \Mockapi\ResourceProvider\FlatFileImplementation([
        'type' => 'message',
        'root' => dirname(dirname(__FILE__)).'/storage',
        'serializer' => new \Mockapi\ResourceProvider\FlatFileImplementation\JsonSerializer
    ]);
} catch (Exception $e) {
    echo "OK - Resource should be instantiated with type argument in plural\n";
}

try {
    $resource = new \Mockapi\ResourceProvider\FlatFileImplementation([
        'type' => 'messages',
        'root' => dirname(dirname(__FILE__)).'/storage',
        'serializer' => new \Mockapi\ResourceProvider\FlatFileImplementation\YamlSerializer
    ]);
} catch (Exception $e) {
    echo "Error - Resource is instantiated with type argument in plural but error occured: ".$e->getMessage()."\n";
}

try {
    $factory = new \Mockapi\ResourceProvider\ResourceProviderFactory([
        ['\Mockapi\ResourceProvider\FlatFileImplementation',[
            'root' => dirname(dirname(__FILE__)).'/storage',
            'serializer' => new \Mockapi\ResourceProvider\FlatFileImplementation\YamlSerializer
        ]]
    ]);
    $resource = $factory->get('messages');
} catch (Exception $e) {
    echo "Error - Factory should create same resource object as above: ".$e->getMessage()."\n";
}

try {
    $resource->add();
} catch (Exception $e) {
    echo "OK - Resource should not create object from empty arguments\n";
}

try {
    $message = $resource->add((object) [
        'message' => 'Hello World',
        'tags' => 'tag'
    ]);
} catch (Exception $e) {
    echo "Error - ".$e->getMessage()."\n";
}

try {
    echo "Created: ";
    $message = $resource->get($message->id);
    var_dump($message);
} catch (Exception $e) {
    echo "Error - ".$e->getMessage()."\n";
}

$message->message = 'Hello again!';

try {
    echo "Updated: ";
    $message = $resource->update($message);
    var_dump($message);
} catch (Exception $e) {
    echo "Error - ".$e->getMessage()."\n";
}

try {
    echo "Testing array attribute: ";
    $message = $resource->add((object) [
        'message' => 'Hello World',
        'tags' => 'tag'
    ]);
    var_dump($message);
} catch (Exception $e) {
    echo "Error - ".$e->getMessage()."\n";
}

$message->tags[] = 'tag2';

try {
    echo "Updating array attribute: ";
    $message = $resource->update($message);
    var_dump($message);
} catch (Exception $e) {
    echo "Error - ".$e->getMessage()."\n";
}

try {
    echo "Adding array attribute value: ";
    $message->tags = $resource->addAttr($message->id, 'tags', 'tag3');
    var_dump($message);
} catch (Exception $e) {
    echo "Error - ".$e->getMessage()."\n";
}

try {
    echo "Removing array attribute value: ";
    $message->tags = $resource->removeAttrValue($message->id, 'tags', ['tag2', 'tag3']);
    var_dump($message);
} catch (Exception $e) {
    echo "Error - ".$e->getMessage()."\n";
}

try {
    echo "Lookup value: ";
    $resource->add((object) ['message' => 'Hello you', 'tags' => 'tag4']);
    $resource->add((object) ['message' => 'Hello me', 'tags' => 'tag5']);
    $resource->add((object) ['message' => 'Hello everybody', 'tags' => 'tag6']);

    $allIds = $resource->find(['message' => 'Hello World', 'tags' => 'tag']);
    var_dump($allIds);
} catch (Exception $e) {
    echo "Error - ".$e->getMessage()."\n";
}



try {
    echo "All: ";
    $allIds = $resource->find();
    var_dump($allIds);
} catch (Exception $e) {
    echo "Error - ".$e->getMessage()."\n";
}

try {
    echo "Delete all: ";
    var_dump($resource->delete($allIds));
} catch (Exception $e) {
    echo "Error - ".$e->getMessage()."\n";
}

echo "\nOK & Ready";
