<?php

namespace Pov\DatagridElasticBundle\DataSource;

use Elastica\Index;
use Elastica\Query;
use InvalidArgumentException;
use Pov\DatagridElasticBundle\DataSource\Elastica\Filter;
use Pov\DatagridElasticBundle\DataSource\Elastica\Paginator;
use ZfcDatagrid\Column\Select;
use ZfcDatagrid\DataSource\AbstractDataSource;

/**
 * Searches documents containing the JSON rows produced by the grid renderer.
 * JoliCode\Elastically\Index extends Elastica\Index and is supported directly.
 */
class Elastica extends AbstractDataSource
{
    private $index;
    private $rootAlias;
    private $fieldMap;
    private $defaultSort;

    public function __construct(Index $index, string $rootAlias, array $fieldMap = [], array $defaultSort = [])
    {
        $this->index = $index;
        $this->rootAlias = $rootAlias;
        $this->fieldMap = $fieldMap;
        $this->defaultSort = $defaultSort;
    }

    public function getData(): Index
    {
        return $this->index;
    }

    public function execute()
    {
        $fields = new ColumnFields($this->rootAlias, $this->fieldMap, $this->index->getMapping());
        $query = ['query' => (new Filter($fields))->applyFilters($this->getFilterGroup())];
        $sort = [];

        foreach ($this->getSortConditions() as $condition) {
            $direction = strtoupper($condition['sortDirection']);
            if (!in_array($direction, ['ASC', 'DESC'], true)) {
                throw new InvalidArgumentException('Invalid sort direction: ' . $direction);
            }
            $sort[] = $fields->sort($condition['column'], $direction);
        }
        if (!$sort) {
            foreach ($this->defaultSort as $field => $direction) {
                $sort[] = $fields->sort(new Select($field), strtoupper($direction));
            }
        }
        // A scroll preserves this ordering across batches, including equal sort keys.
        $sort[] = ['_doc' => 'asc'];
        $query['sort'] = $sort;
        $query['track_total_hits'] = true;

        $sourceFields = [];
        foreach (array_merge($this->getColumns(), $this->getGroupConditions()) as $column) {
            if ($column instanceof Select) {
                $sourceFields[] = $fields->sourceField($column);
            }
        }
        $query['_source'] = array_values(array_unique($sourceFields));

        $this->setPaginatorAdapter(new Paginator(
            $this->index,
            new Query($query),
            $fields,
            $this->getColumns(),
            $this->getGroupConditions()
        ));
    }
}
