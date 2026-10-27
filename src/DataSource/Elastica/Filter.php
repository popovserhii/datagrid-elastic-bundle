<?php

namespace Pov\DatagridElasticBundle\DataSource\Elastica;

use InvalidArgumentException;
use Pov\DatagridElasticBundle\DataSource\ColumnFields;
use ZfcDatagrid\Filter as DatagridFilter;
use ZfcDatagrid\FilterGroup;

class Filter
{
    private $fields;

    public function __construct(ColumnFields $fields)
    {
        $this->fields = $fields;
    }

    public function applyFilters($group): array
    {
        return $group instanceof FilterGroup ? $this->applyFilter($group) : ['match_all' => new \stdClass()];
    }

    public function applyFilter(FilterGroup $group): array
    {
        $clauses = [];
        foreach ($group->getFilters() as $filter) {
            foreach ($this->clauses($filter) as $clause) {
                $clauses[] = $clause;
            }
        }
        // A group may contain only subgroups, with no direct rules.
        foreach ($group->getGroups() as $child) {
            if ($child->getFilters() || $child->getGroups()) {
                $clauses[] = $this->applyFilter($child);
            }
        }
        if (!$clauses) {
            return ['match_all' => new \stdClass()];
        }
        if (FilterGroup::COND_OR === $group->getCondition()) {
            return ['bool' => ['should' => $clauses, 'minimum_should_match' => 1]];
        }
        if (FilterGroup::COND_AND !== $group->getCondition()) {
            throw new InvalidArgumentException('Unsupported filter group condition: ' . $group->getCondition());
        }

        return ['bool' => ['filter' => $clauses]];
    }

    private function clauses(DatagridFilter $filter): array
    {
        $column = $filter->getColumn();
        $field = $this->fields->sourceField($column);
        $operator = $filter->getOperator();
        $values = $filter->getValues();
        $exists = $this->fields->wrapNested($field, ['exists' => ['field' => $field]]);
        if (DatagridFilter::NULL === $operator) {
            return [['bool' => ['must_not' => [$exists]]]];
        }
        if (DatagridFilter::NOT_NULL === $operator) {
            return [$exists];
        }

        $negative = in_array($operator, [
            DatagridFilter::NOT_EQUAL, DatagridFilter::NOT_IN, DatagridFilter::NOT_LIKE,
            DatagridFilter::NOT_LIKE_LEFT, DatagridFilter::NOT_LIKE_RIGHT,
        ], true);
        $exact = $this->fields->exactField($column);
        $queries = [];
        if (in_array($operator, [DatagridFilter::IN, DatagridFilter::NOT_IN], true)) {
            $queries[] = $values ? ['terms' => [$exact => array_values($values)]] : ['match_none' => new \stdClass()];
        } elseif (DatagridFilter::BETWEEN === $operator) {
            if (2 !== count($values)) {
                throw new InvalidArgumentException('BETWEEN requires two values.');
            }
            $queries[] = $this->range($field, ['gte' => $values[0], 'lte' => $values[1]]);
        } else {
            foreach ($values as $value) {
                switch ($operator) {
                    case DatagridFilter::EQUAL:
                    case DatagridFilter::NOT_EQUAL:
                        $query = ['term' => [$exact => $value]];
                        break;
                    case DatagridFilter::LIKE:
                    case DatagridFilter::NOT_LIKE:
                    case DatagridFilter::LIKE_LEFT:
                    case DatagridFilter::NOT_LIKE_LEFT:
                    case DatagridFilter::LIKE_RIGHT:
                    case DatagridFilter::NOT_LIKE_RIGHT:
                        $pattern = strtr((string) $value, ['\\' => '\\\\', '*' => '\\*', '?' => '\\?']);
                        if (in_array($operator, [DatagridFilter::LIKE, DatagridFilter::NOT_LIKE, DatagridFilter::LIKE_LEFT, DatagridFilter::NOT_LIKE_LEFT], true)) {
                            $pattern = '*' . $pattern;
                        }
                        if (in_array($operator, [DatagridFilter::LIKE, DatagridFilter::NOT_LIKE, DatagridFilter::LIKE_RIGHT, DatagridFilter::NOT_LIKE_RIGHT], true)) {
                            $pattern .= '*';
                        }
                        $query = ['wildcard' => [$exact => ['value' => $pattern, 'case_insensitive' => true]]];
                        break;
                    case DatagridFilter::GREATER:
                    case DatagridFilter::GREATER_EQUAL:
                    case DatagridFilter::LESS:
                    case DatagridFilter::LESS_EQUAL:
                        $ranges = [DatagridFilter::GREATER => 'gt', DatagridFilter::GREATER_EQUAL => 'gte', DatagridFilter::LESS => 'lt', DatagridFilter::LESS_EQUAL => 'lte'];
                        $query = $this->range($field, [$ranges[$operator] => $value]);
                        break;
                    default:
                        throw new InvalidArgumentException('Unsupported filter operator: ' . $operator);
                }
                $queries[] = $query;
            }
        }

        foreach ($queries as &$query) {
            $query = $this->fields->wrapNested($field, $query);
            if ($negative) {
                // Negate outside nested: no child may match. Missing values follow SQL NULL semantics.
                $query = ['bool' => ['filter' => [$exists], 'must_not' => [$query]]];
            }
        }

        return $queries;
    }

    private function range(string $field, array $bounds): array
    {
        if (in_array($this->fields->definition($field)['type'] ?? null, ['date', 'date_nanos'], true)) {
            $bounds['format'] = 'yyyy-MM-dd HH:mm:ss||strict_date_optional_time||epoch_millis';
        }

        return ['range' => [$field => $bounds]];
    }
}
