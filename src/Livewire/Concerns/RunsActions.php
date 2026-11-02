<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Livewire\Concerns;

use Acunsoft\DataTable\Laravel\Action;

/**
 * Row and bulk actions.
 *
 * @internal part of {@see \Acunsoft\DataTable\Laravel\Livewire\WithDataTable}; use that trait.
 */
trait RunsActions
{
    /**
     * Run a row action. The row is looked up inside `query()`, and the action
     * must exist and be visible for that row; otherwise nothing happens.
     */
    public function runRowAction(string $action, string|int $key): mixed
    {
        $found = $this->findAction($this->dataTableRowActions(), $action);
        $key = (string) $key;
        if ($found === null || strlen($key) > 64) {
            return null;
        }
        $row = $this->whereKeys($this->query(), [$key])->first();
        if ($row === null || ! $found->isVisible($row)) {
            return null;
        }
        $result = $found->run($row);
        unset($this->rows); // show the changed data

        return $result;
    }

    /** Run a bulk action on the selection, then clear the selection. */
    public function runBulkAction(string $action): mixed
    {
        $found = $this->findAction($this->dataTableBulkActions(), $action);
        if ($found === null || ! $found->isVisible() || $this->selectedCount() === 0) {
            return null;
        }
        if ($found->isQueued()) {
            if ($this->queueTableTask('bulk', $found->id())) {
                $this->clearSelection();
            }

            return null;
        }
        $result = $found->run($this->selectedQuery());
        $this->clearSelection();
        unset($this->rows);

        return $result;
    }

    /** @return list<Action> */
    protected function dataTableRowActions(): array
    {
        return $this->checkedActions($this->rowActions(), 'rowActions');
    }

    /** @return list<Action> */
    protected function dataTableBulkActions(): array
    {
        return array_values(array_filter(
            $this->checkedActions($this->bulkActions(), 'bulkActions'),
            fn (Action $action) => $action->isVisible(),
        ));
    }

    /** @return list<Action> */
    private function checkedActions(array $actions, string $method): array
    {
        $seen = [];
        foreach ($actions as $action) {
            if (! $action instanceof Action) {
                throw new \UnexpectedValueException(static::class."::{$method}() must return Action instances.");
            }
            if (isset($seen[$action->id()])) {
                throw new \UnexpectedValueException(static::class."::{$method}() has the action [{$action->id()}] twice.");
            }
            $seen[$action->id()] = true;
        }

        return array_values($actions);
    }

    /** @param list<Action> $actions */
    private function findAction(array $actions, string $id): ?Action
    {
        foreach ($actions as $action) {
            if ($action->id() === $id) {
                return $action;
            }
        }

        return null;
    }
}
