<?php

namespace Pov\DatagridElasticBundle\DataSource\Elastica;

use Elastica\Client;
use InvalidArgumentException;
use Pov\DatagridElasticBundle\DataSource\Elastica;

class Factory
{
    private $client;
    private $resources;

    /** Resource names are application-owned; callers cannot choose arbitrary indices. */
    public function __construct(Client $client, array $resources)
    {
        $this->client = $client;
        $this->resources = $resources;
    }

    public function create(string $resource): Elastica
    {
        if (!isset($this->resources[$resource])) {
            throw new InvalidArgumentException('Unknown datagrid resource: ' . $resource);
        }
        $config = $this->resources[$resource];

        return new Elastica(
            $this->client->getIndex($config['index']),
            $config['root_alias'] ?? $resource,
            $config['fields'] ?? [],
            $config['default_sort'] ?? []
        );
    }
}
