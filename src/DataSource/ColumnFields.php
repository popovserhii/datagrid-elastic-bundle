<?php

namespace Pov\DatagridElasticBundle\DataSource;

use InvalidArgumentException;
use ZfcDatagrid\Column\AbstractColumn;
use ZfcDatagrid\Column\Select;

/** Maps SQL column identifiers to fields in rendered grid row documents. */
class ColumnFields
{
    private $rootAlias;
    private $fieldMap;
    private $mapping;

    public function __construct(string $rootAlias, array $fieldMap = [], array $mapping = [])
    {
        $this->rootAlias = $rootAlias;
        $this->fieldMap = $fieldMap;
        $this->mapping = $mapping;
    }

    public function field(AbstractColumn $column): string
    {
        if (!$column instanceof Select) {
            throw new InvalidArgumentException('This column is not selectable: ' . $column->getUniqueId());
        }
        $id = $column->getUniqueId();
        $options = $column->getRendererParameters('elasticSearch');
        $field = $this->fieldMap[$id] ?? $options['field'] ?? null;
        if (null === $field) {
            // Expressions are already materialised by the source grid: never send SQL.
            $parts = explode('_', $id, 2);
            if ($column->getSelectPart1() === $id && null === $column->getSelectPart2()) {
                $field = $id;
            } else {
                $field = isset($parts[1])
                    ? ($parts[0] === $this->rootAlias ? $parts[1] : $parts[0] . '.' . $parts[1])
                    : $id;
            }
        }
        if (!is_string($field) || !preg_match('/\A[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*\z/', $field)) {
            throw new InvalidArgumentException('Invalid document field for column: ' . $id);
        }

        return $this->resolveField($field);
    }

    public function sourceField(AbstractColumn $column): string
    {
        $field = $this->field($column);
        $definition = $this->definition($field);

        return 'alias' === ($definition['type'] ?? null) ? $definition['path'] : $field;
    }

    public function exactField(AbstractColumn $column): string
    {
        $field = $this->sourceField($column);
        $definition = $this->definition($field);
        if ('text' === ($definition['type'] ?? null)) {
            foreach ($definition['fields'] ?? [] as $name => $subfield) {
                if ('keyword' === ($subfield['type'] ?? null)) {
                    return $field . '.' . $name;
                }
            }
            throw new InvalidArgumentException('A keyword subfield is required for exact filtering/sorting: ' . $field);
        }
        if (in_array($definition['type'] ?? null, ['object', 'nested'], true) || isset($definition['properties'])) {
            throw new InvalidArgumentException('Filter/sort/group a scalar child field instead of: ' . $field);
        }

        return $field;
    }

    public function definition(string $field): array
    {
        $field = $this->resolveField($field);
        $definition = $this->mapping;
        $parts = explode('.', $field);
        while ($parts) {
            // Native field aliases may be stored as a single dotted property name.
            $remaining = implode('.', $parts);
            if (isset($definition['properties'][$remaining])) {
                return $definition['properties'][$remaining];
            }
            $part = array_shift($parts);
            $definition = $definition['properties'][$part] ?? $definition['fields'][$part] ?? [];
        }

        return $definition;
    }

    private function resolveField(string $field): string
    {
        $aliases = $this->mapping['_meta']['nested_filter_aliases'] ?? [];
        $parts = explode('.', $field);
        while ($parts) {
            $prefix = implode('.', $parts);
            if (isset($aliases[$prefix])) {
                return $aliases[$prefix] . substr($field, strlen($prefix));
            }
            array_pop($parts);
        }

        return $field;
    }

    public function nestedPaths(string $field): array
    {
        $paths = [];
        $path = '';
        foreach (explode('.', $field) as $part) {
            $path = '' === $path ? $part : $path . '.' . $part;
            if ('nested' === ($this->definition($path)['type'] ?? null)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    public function wrapNested(string $field, array $query): array
    {
        foreach (array_reverse($this->nestedPaths($field)) as $path) {
            $query = ['nested' => ['path' => $path, 'query' => $query, 'score_mode' => 'none']];
        }

        return $query;
    }

    public function sort(AbstractColumn $column, string $direction): array
    {
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            throw new InvalidArgumentException('Invalid sort direction: ' . $direction);
        }
        $field = $this->exactField($column);
        $options = ['order' => strtolower($direction), 'missing' => '_last', 'unmapped_type' => 'keyword'];
        $nested = null;
        foreach (array_reverse($this->nestedPaths($field)) as $path) {
            $nested = ['path' => $path] + (null === $nested ? [] : ['nested' => $nested]);
        }
        if (null !== $nested) {
            $options['nested'] = $nested;
        }

        return [$field => $options];
    }

    public function project(array $source, array $columns): array
    {
        $row = [];
        foreach ($columns as $column) {
            if ($column instanceof Select) {
                $row[$column->getUniqueId()] = $this->read($source, $this->sourceField($column));
            }
        }

        return $row;
    }

    public function read(array $source, string $field)
    {
        if (array_key_exists($field, $source)) {
            return $source[$field];
        }
        $parts = explode('.', $field, 2);
        if (!array_key_exists($parts[0], $source)) {
            return null;
        }
        $value = $source[$parts[0]];
        if (!isset($parts[1]) || null === $value) {
            return $value;
        }
        if (!is_array($value)) {
            return null;
        }
        if (isset($value[0]) && is_array($value[0])) {
            return array_map(function (array $item) use ($parts) {
                return $this->read($item, $parts[1]);
            }, $value);
        }

        return $this->read($value, $parts[1]);
    }
}
