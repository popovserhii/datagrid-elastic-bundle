<?php

namespace Pov\DatagridElasticBundle\DataSource\Elastica;

use Elastica\Index;
use Elastica\Query;
use InvalidArgumentException;
use Laminas\Paginator\Adapter\AdapterInterface;
use Pov\DatagridElasticBundle\DataSource\ColumnFields;

class Paginator implements AdapterInterface
{
    private $index;
    private $query;
    private $fields;
    private $columns;
    private $groups;
    private $total;
    private $groupedRows;

    public function __construct(Index $index, Query $query, ColumnFields $fields, array $columns, array $groups = [])
    {
        $this->index = $index;
        $this->query = $query;
        $this->fields = $fields;
        $this->columns = $columns;
        $this->groups = $groups;
        foreach ($groups as $column) {
            $fields->exactField($column);
        }
    }

    public function count(): int
    {
        if (null === $this->total) {
            if ($this->groups) {
                $this->total = count($this->groupedRows());
            } else {
                $query = clone $this->query;
                $query->setSize(0);
                $results = $this->search($query)->search();
                $this->total = $results->getTotalHits();
            }
        }

        return $this->total;
    }

    public function getItems($offset, $itemCountPerPage): array
    {
        $offset = (int) $offset;
        $limit = (int) $itemCountPerPage;
        if ($offset < 0 || $limit < -1) {
            throw new InvalidArgumentException('Offset must be nonnegative; limit must be -1 or nonnegative.');
        }
        if (0 === $limit) {
            return [];
        }
        if ($this->groups) {
            return array_slice($this->groupedRows(), $offset, -1 === $limit ? null : $limit);
        }

        $rows = [];
        foreach ($this->documents() as $position => $source) {
            if ($position < $offset) {
                continue;
            }
            $rows[] = $this->fields->project($source, $this->columns);
            if (-1 !== $limit && count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    private function groupedRows(): array
    {
        if (null === $this->groupedRows) {
            $rows = [];
            $seen = [];
            foreach ($this->documents() as $source) {
                $values = [];
                foreach ($this->groups as $column) {
                    $value = $this->fields->read($source, $this->fields->sourceField($column));
                    if (is_array($value) || is_object($value)) {
                        throw new InvalidArgumentException('Grouping requires a single scalar value: ' . $column->getUniqueId());
                    }
                    $values[] = $value;
                }
                $key = json_encode($values, JSON_THROW_ON_ERROR);
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $rows[] = $this->fields->project($source, $this->columns);
                }
            }
            $this->groupedRows = $rows;
        }

        return $this->groupedRows;
    }

    private function search(Query $query)
    {
        // Grid rows are arrays, not hydrated DTOs. This also works with Elastically 1.4.
        return $this->index->createSearch($query, null, new ResultSetBuilder());
    }

    private function documents(): \Generator
    {
        $query = clone $this->query;
        $query->setSize(500);
        $scroll = $this->search($query)->scroll('1m');
        try {
            foreach ($scroll as $results) {
                foreach ($results->getResults() as $result) {
                    yield $result->getSource();
                }
            }
        } finally {
            $scroll->clear();
        }
    }
}
