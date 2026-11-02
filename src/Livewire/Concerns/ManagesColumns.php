<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Livewire\Concerns;

use Acunsoft\DataTable\Laravel\Column;

/**
 * Column visibility, order and widths, kept in the URL and the component.
 *
 * @internal part of {@see \Acunsoft\DataTable\Laravel\Livewire\WithDataTable}; use that trait.
 */
trait ManagesColumns
{
    /** Show or hide a column; one column always stays visible. */
    public function toggleColumn(string $id): void
    {
        $column = $this->dataTableColumn($id);
        if ($column === null || ! $column->isHideable()) {
            return;
        }
        $hidden = $this->hiddenColumnIds();
        $next = in_array($id, $hidden, true) ? array_values(array_diff($hidden, [$id])) : [...$hidden, $id];
        if (count($next) < count($this->dataTableColumns())) {
            $this->hiddenColumns = $next;
        }
    }

    /** @return list<Column> the columns to show, in declaration order */
    protected function dataTableVisibleColumns(): array
    {
        $hidden = $this->hiddenColumnIds();

        return array_values(array_filter($this->orderedColumns(), fn (Column $column) => ! in_array($column->id(), $hidden, true)));
    }

    /** @return list<Column> every column in display order */
    protected function orderedColumns(): array
    {
        $byId = [];
        foreach ($this->dataTableColumns() as $column) {
            $byId[$column->id()] = $column;
        }
        $ordered = [];
        if ($this->reorderableColumns()) {
            foreach ($this->columnOrder as $id) {
                if (is_string($id) && isset($byId[$id])) {
                    $ordered[$id] = $byId[$id];
                }
            }
        }

        return array_values($ordered + $byId);
    }

    /** @return array<string, int> valid widths in pixels per column ID, plus `__actions` for the actions column */
    protected function columnWidthsPx(): array
    {
        if (! $this->resizableColumns()) {
            return [];
        }
        $widths = [];
        foreach ([...array_map(fn (Column $column) => $column->id(), $this->dataTableColumns()), '__actions'] as $id) {
            $width = $this->columnWidths[$id] ?? null;
            if (is_int($width) || is_float($width) || (is_string($width) && ctype_digit($width))) {
                $widths[$id] = max(48, min(2000, (int) round((float) $width)));
            }
        }

        return $widths;
    }

    /** Move a column to a position of the display order (0 = first). */
    public function moveColumn(string $id, int $toIndex): void
    {
        $ids = array_map(fn (Column $column) => $column->id(), $this->orderedColumns());
        if (! $this->reorderableColumns() || ! in_array($id, $ids, true)) {
            return;
        }
        $rest = array_values(array_diff($ids, [$id]));
        array_splice($rest, max(0, min(count($rest), $toIndex)), 0, [$id]);
        $this->columnOrder = $rest;
    }

    /** Move a column before or after another (a header dropped on a header). */
    public function moveColumnNextTo(string $id, string $target, bool $after = false): void
    {
        $rest = array_values(array_diff(array_map(fn (Column $column) => $column->id(), $this->orderedColumns()), [$id]));
        $index = array_search($target, $rest, true);
        if ($index !== false && $id !== $target) {
            $this->moveColumn($id, $index + ($after ? 1 : 0));
        }
    }

    /**
     * Set column widths in pixels (`null` returns a column to its natural
     * width); `__actions` is the actions column. Unknown IDs are ignored.
     *
     * @param  array<string, mixed>  $widths
     */
    public function resizeColumns(array $widths): void
    {
        if (! $this->resizableColumns()) {
            return;
        }
        $known = [...array_map(fn (Column $column) => $column->id(), $this->dataTableColumns()), '__actions'];
        $next = $this->columnWidthsPx();
        foreach ($widths as $id => $width) {
            if (! in_array($id, $known, true)) {
                continue;
            }
            if ($width === null) {
                unset($next[$id]);
            } elseif (is_int($width) || is_float($width)) {
                $next[$id] = max(48, min(2000, (int) round($width)));
            }
        }
        $this->columnWidths = $next;
    }

    /** Back to the definition order and natural widths. */
    public function resetColumns(): void
    {
        $this->columnOrder = [];
        $this->columnWidths = [];
    }

    /** Whether the order or widths differ from the defaults. */
    protected function columnsChanged(): bool
    {
        $natural = array_map(fn (Column $column) => $column->id(), $this->dataTableColumns());

        return $this->columnWidthsPx() !== [] || array_map(fn (Column $column) => $column->id(), $this->orderedColumns()) !== $natural;
    }

    /** @return list<string> valid hidden IDs: known, hideable, never all of them */
    protected function hiddenColumnIds(): array
    {
        $hidden = [];
        foreach ($this->dataTableColumns() as $column) {
            if ($column->isHideable() && in_array($column->id(), $this->hiddenColumns, true)) {
                $hidden[] = $column->id();
            }
        }

        return count($hidden) >= count($this->dataTableColumns()) ? [] : $hidden;
    }
}
