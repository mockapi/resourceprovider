<?php

namespace Mockapi\ResourceProvider;

use \Mockapi\Interfaces\ResourceProviderInterface;
use \Mockapi\Interfaces\ResourceProviderFactoryInterface;

use \Mockapi\Validate\Validate;
use \Exception;

class ResourceProviderFactory implements ResourceProviderFactoryInterface
{
    protected $defaultResourceProviderClass;
    protected $defaultResourceProviderArgs;

    protected $providers;
    protected $strict = true;

    /**
     * Factory constructor
     *
     * Example:
     *
     * ```
     * $providers = [
     *   // Specific provider
     *   'messages' => new Mockapi\ResourceProvider\FlatFileImplementation('messages', [
     *     'type' => 'messages',
     *     'root' => './storage/',
     *     'serializer' => new \Mockapi\ResourceProvider\FlatFileImplementation\YamlSerializer
     *   ]),
     *   // Default provider to create any possible ResourceProvider
     *   ['Mockapi\ResourceProvider\FlatFileImplementation', [
     *     'root' => './storage/',
     *     'serializer' => new \Mockapi\ResourceProvider\FlatFileImplementation\YamlSerializer
     *   ]]
     * ];
     *
     * $factory = new Factory($providers);
     *
     * // Most flexible Factory:
     * $factory = new Factory([['Mockapi\ResourceProvider\FlatFileImplementation', [
     *     'root' => './storage/',
     *     'serializer' => new \Mockapi\ResourceProvider\FlatFileImplementation\YamlSerializer
     *   ]]);
     * ```
     *
     * @param array $providers
     * @returns void
     *
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $type => &$provider) {
            if (is_object($provider)) {
                if (!$provider instanceof Mockapi\Interfaces\ResourceProviderInterface) {
                    throw new Exception('Provider must inherit ResourceProviderInterface');
                }
            } elseif (is_array($provider) && count($provider) === 2) { // Lazyloaders >>>
                Validate::isNonEmptyString($provider[0], 'Resource provider class name');

                if (!class_exists($provider[0])) {
                    throw new Exception("[Resource Provider Factory service Error][DEV]: Missing resource implementation `$provider[0]`");
                }

                if (!in_array('Mockapi\Interfaces\ResourceProviderInterface', class_implements($provider[0]))) {
                    throw new Exception("[Resource Provider Factory service Error][DEV]: Incompatibe resource `$provider[0]`");
                }

                if (!is_array($provider[1])) {
                    throw new Exception('Provider[1] argument must be array of arguments');
                }
            } else {
                throw new Exception('Provider argument must be object or array');
            }

            // Default factory
            if ($type == 0) {
                if (!is_array($provider)) {
                    throw new Exception('Creating automatic factory, you need to pass 2 item array of [(string) classname, (array) arguments]');
                }

                $this->strict = false;

                $this->defaultResourceProviderClass = $provider[0];
                $this->defaultResourceProviderArgs = $provider[1];
            } else {
                $this->services[$type] = $provider;
            }
        }
    }

    public function index()
    {
        return [];
        return [
            'requests' => '{resource.link}/{object.id}/{object.attr}',
            'methods'  => ['GET', 'POST', 'PATCH', 'DELETE'],
            'resources' => array_map(function($v) {
                return (object) [
                    'type' => $v,
                    'link' => $this->endpoint."/{$v}"
                ];
            }, array_keys($this->services))
        ];
    }

    public function get($type)
    {
        // Non-strict mode, create default resource
        if (!isset($this->services[$type]) && !$this->strict) {
            $this->services[$type] = [
                $this->defaultResourceProviderClass,
                $this->defaultResourceProviderArgs
            ];
        }

        if (isset($this->services[$type])) {
            if (is_array($this->services[$type])) {
                $this->services[$type] = new $this->services[$type][0](array_merge($this->services[$type][1], ['type' => $type]));
            }

            return $this->services[$type];
        }

        throw new Exception("Unable to instantiate Resource {$type} provider");
    }
}
