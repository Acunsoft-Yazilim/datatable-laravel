<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Livewire\Concerns;

use Acunsoft\DataTable\Laravel\InvalidTableQuery;
use Acunsoft\DataTable\Laravel\TableExport;
use Acunsoft\DataTable\Laravel\TableQuery;

/**
 * Exports of the matching or selected rows.
 *
 * @internal part of {@see \Acunsoft\DataTable\Laravel\Livewire\WithDataTable}; use that trait.
 */
trait ExportsRows
{
    /**
     * Download the visible columns of every row matching the current search,
     * filters and sorting (or of the selected rows, when there is a
     * selection), as xlsx or CSV. Only formats from `exportFormats()` work.
     */
    public function export(string $format): mixed
    {
        if (! in_array($format, $this->exportFormats(), true) || ! in_array($format, ['xlsx', 'csv'], true)) {
            return null;
        }
        $query = $this->dataTableQuery();
        $selected = $this->selectable() && $this->selectedCount() > 0;
        $export = TableExport::for($selected ? $this->selectedQuery() : $this->query())
            ->columns($this->dataTableVisibleColumns())
            ->key($this->keyName())
            ->limit($this->exportLimit())
            ->query($selected
                ? TableQuery::fromArray(['sort' => implode(',', array_map(fn (array $rule) => "{$rule['field']}:{$rule['direction']}", $query->sorting))])
                : $query, $this->dataTableColumns());
        $name = $this->exportFilename();
        try {
            return $format === 'xlsx'
                ? $export->xlsx("{$name}.xlsx", $name)
                : $export->csv("{$name}.csv", $this->exportDelimiter());
        } catch (InvalidTableQuery $error) {
            if ($this->queuedExports() && isset($error->errors['export'])) {
                $this->queueTableTask('export', $format); // too many rows for a download now: prepare it in the background

                return null;
            }
            $this->addError('export', implode(' ', $error->errors));

            return null;
        }
    }
}
