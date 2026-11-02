<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Livewire\Concerns;

use Acunsoft\DataTable\Laravel\QueryApplier;
use Acunsoft\DataTable\Laravel\TableQuery;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Row selection: single rows, ranges, the page, and every matching row.
 *
 * @internal part of {@see \Acunsoft\DataTable\Laravel\Livewire\WithDataTable}; use that trait.
 */
trait SelectsRows
{
    protected function isSelected(string $key): bool
    {
        return $this->allMatching
            ? ! in_array($key, $this->excluded, true)
            : in_array($key, $this->selectedKeys(), true);
    }

    public function toggleRow(string|int $key): void
    {
        $key = (string) $key;
        if ($this->allMatching) {
            // Any key the browser sends: kept short and at most maxSelected(), as the selection is.
            $this->excluded = in_array($key, $this->excluded, true)
                ? array_values(array_diff($this->excluded, [$key]))
                : self::distinctKeys([...$this->excluded, $key], $this->maxSelected());

            return;
        }
        $selected = $this->selectedKeys();
        if (in_array($key, $selected, true)) {
            $this->selected = array_values(array_diff($selected, [$key]));
        } elseif (count($selected) < $this->maxSelected()) {
            $this->selected = [...$selected, $key];
        }
    }

    /**
     * Shift+click: select (`$select` true) or clear every row of the current
     * page from `$from` to `$to`, both included. Keys not on the page are ignored.
     */
    public function selectRange(string|int $from, string|int $to, bool $select = true): void
    {
        $page = $this->pageKeys();
        $start = array_search((string) $from, $page, true);
        $end = array_search((string) $to, $page, true);
        if ($start === false || $end === false) {
            return;
        }
        $range = array_slice($page, min($start, $end), abs($end - $start) + 1);
        if ($this->allMatching) {
            $this->excluded = $select
                ? array_values(array_diff($this->excluded, $range))
                : self::distinctKeys([...$this->excluded, ...$range], $this->maxSelected());

            return;
        }
        $selected = $this->selectedKeys();
        $this->selected = $select
            ? array_slice(array_values(array_unique([...$selected, ...$range])), 0, max(count($selected), $this->maxSelected()))
            : array_values(array_diff($selected, $range));
    }

    /** Select the rows of the current page, or clear them when all are selected. */
    public function togglePage(): void
    {
        $keys = $this->pageKeys();
        $all = $keys !== [] && array_reduce($keys, fn (bool $carry, string $key) => $carry && $this->isSelected($key), true);
        if ($this->allMatching) {
            $this->excluded = $all
                ? self::distinctKeys([...$this->excluded, ...$keys], $this->maxSelected())
                : array_values(array_diff($this->excluded, $keys));

            return;
        }
        $selected = $this->selectedKeys();
        $this->selected = $all
            ? array_values(array_diff($selected, $keys))
            : array_slice(array_values(array_unique([...$selected, ...$keys])), 0, $this->maxSelected());
    }

    /** 'none', 'some' or 'all' for the rows on the current page. */
    protected function pageSelection(): string
    {
        $keys = $this->pageKeys();
        $count = count(array_filter($keys, fn (string $key) => $this->isSelected($key)));

        return match (true) {
            $count === 0 => 'none',
            $count === count($keys) => 'all',
            default => 'some',
        };
    }

    public function selectAllMatching(): void
    {
        $this->allMatching = true;
        $this->excluded = [];
        $this->selected = [];
    }

    public function clearSelection(): void
    {
        $this->allMatching = false;
        $this->excluded = [];
        $this->selected = [];
    }

    /** The selection size; for "all matching" a bounded count (see selectionSize()). */
    protected function selectedCount(): int
    {
        return $this->selectionSize()[0];
    }

    /** @return array{0: int, 1: bool} the selection size and whether it is exact */
    protected function selectionSize(): array
    {
        return $this->allMatching
            ? QueryApplier::count($this->selectedQuery(), $this->countLimit(), $this->countTimeout())
            : [count($this->selectedKeys()), true];
    }

    /**
     * The selected rows as a query inside `query()`, for bulk actions:
     * `$this->selectedQuery()->update([...])` or `->get()`.
     */
    protected function selectedQuery(): EloquentBuilder|QueryBuilder
    {
        if ($this->allMatching) {
            $query = $this->dataTableQuery();
            $matching = TableQuery::fromArray(['filters' => $this->wireFilters($query), 'search' => $query->search]);
            if ($query->searchKeys !== null) {
                $matching = $matching->withSearchKeys($query->searchKeys, $query->searchKeyColumn); // the engine's rows, as on screen
            }
            $builder = QueryApplier::apply($this->query(), $matching, $this->dataTableColumns());

            return $this->excluded === [] ? $builder : $this->whereKeys($builder, $this->excluded, not: true);
        }

        return $this->whereKeys($this->query(), $this->selectedKeys());
    }
}
