<?php

namespace Pov\DatagridElasticBundle\DataSource\Elastica;

use Elastica\Query;
use Elastica\Response;
use Elastica\ResultSet;
use Elastica\ResultSet\DefaultBuilder;
use RuntimeException;

/** Validate every response, including empty and subsequent scroll batches. */
class ResultSetBuilder extends DefaultBuilder
{
    public function buildResultSet(Response $response, Query $query): ResultSet
    {
        $results = parent::buildResultSet($response, $query);
        $data = $response->getData();
        if ($results->hasTimedOut() || ($data['_shards']['failed'] ?? 0) > 0 || 'eq' !== $results->getTotalHitsRelation()) {
            throw new RuntimeException('Elasticsearch returned an incomplete datagrid result.');
        }

        return $results;
    }
}
