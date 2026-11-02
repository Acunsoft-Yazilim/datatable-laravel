<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Livewire\Concerns;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\TableQuery;
use Acunsoft\DataTable\Laravel\Support\FilterPanel;

/**
 * Sorting, filters and the search box.
 *
 * @internal part of {@see \Acunsoft\DataTable\Laravel\Livewire\WithDataTable}; use that trait.
 */
trait SortsAndFilters
{
    /**
     * A click cycles ascending → descending → none. With `$additive` (Shift+click
     * in the view) the column is added to or changed in a multi-column sort.
     */
    public function sortBy(string $field, bool $additive = false): void
    {
        $additive = $additive && $this->multiSort();
        $column = $this->dataTableColumn($field);
        if ($column === null || ! $column->isSortable()) {
            return;
        }
        $current = [];
        foreach ($this->sorting() as $rule) {
            $current[$rule['field']] = $rule['direction'];
        }
        $existing = $current[$field] ?? null;
        $direction = $existing === null ? 'asc' : ($existing === 'asc' ? 'desc' : null);
        if (! $additive) {
            $current = $direction === null ? [] : [$field => $direction];
        } elseif ($direction === null) {
            unset($current[$field]);
        } else {
            $current[$field] = $direction; // keeps its position when it was already sorted
        }
        $current = array_slice($current, 0, TableQuery::MAX_SORT_RULES, true);
        $this->sort = implode(',', array_map(fn ($f, $d) => "{$f}:{$d}", array_keys($current), $current));
        $this->cursors = [];
        $this->resetPage();
    }

    /** Add or replace the rule for this field and operator. */
    public function setFilter(string $field, string $op, mixed $value = null): void
    {
        $rules = array_values(array_filter(
            $this->dataTableQuery()->filters,
            fn (array $rule) => ! ($rule['field'] === $field && $rule['operator'] === $op),
        ));
        $rules = array_map(fn (array $rule) => ['field' => $rule['field'], 'op' => $rule['operator'], 'value' => $rule['value']], $rules);
        $candidate = ['field' => $field, 'op' => $op, 'value' => $value];
        if ($this->validFilter($candidate)) {
            $rules[] = $candidate;
        }
        $this->filters = array_slice($rules, 0, TableQuery::MAX_FILTERS);
        $this->tableChanged();
    }

    /**
     * Set one filter-panel column (`Column::filter()` / `options()`) from its
     * control's values: `text`, `selected`, `min` / `max` or `flag`; empty
     * values remove its filter. Values are checked like every other rule.
     *
     * @param array<string, mixed> $values
     */
    public function filterBy(string $field, array $values = []): void
    {
        $column = $this->dataTableColumn($field);
        if ($column === null || ! $column->hasFilter()) {
            return;
        }
        $next = array_values(array_filter(FilterPanel::rules($column, $values), fn (array $rule) => $this->validFilter($rule)));
        $others = array_values(array_filter($this->wireFilters($this->dataTableQuery()), fn (array $rule) => $rule['field'] !== $field));
        $this->filters = array_slice([...$others, ...$next], 0, TableQuery::MAX_FILTERS);
        $this->tableChanged();
        $this->dispatch('adt-filters-synced'); // inputs the user typed in follow the new values
    }

    /** Remove the filters of the filter-panel columns (other filters stay). */
    public function clearPanelFilters(): void
    {
        $panel = array_map(fn (Column $column) => $column->id(), array_filter($this->dataTableColumns(), fn (Column $column) => $column->hasFilter()));
        $this->filters = array_values(array_filter($this->wireFilters($this->dataTableQuery()), fn (array $rule) => ! in_array($rule['field'], $panel, true)));
        $this->tableChanged();
        $this->dispatch('adt-filters-synced');
    }

    public function removeFilter(int $index): void
    {
        $filters = array_values($this->filters);
        unset($filters[$index]);
        $this->filters = array_values($filters);
        $this->tableChanged();
    }

    public function clearFilters(): void
    {
        $this->filters = [];
        $this->search = '';
        $this->tableChanged();
    }

    public function updatedSearch(): void
    {
        $this->tableChanged();
    }

    public function updatedFilters(): void
    {
        $this->tableChanged();
    }

    public function updatedPageSize(): void
    {
        $this->cursors = [];
        $this->resetPage();
    }
}
