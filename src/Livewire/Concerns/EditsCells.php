<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Livewire\Concerns;

use Acunsoft\DataTable\Laravel\Column;

/**
 * Editing in place: validation, the column's save and the policy check.
 *
 * @internal part of {@see \Acunsoft\DataTable\Laravel\Livewire\WithDataTable}; use that trait.
 */
trait EditsCells
{
    /**
     * Save a cell edited in place (columns with `editable()`). The row is
     * looked up inside `query()`; the model's policy must allow `update`
     * (`authorizeEdit()`; without one, `editableWithoutPolicy()`); the value is validated against the
     * column (`Column::editRules()`), and a failure shows next to the field.
     * Returns whether it was saved.
     */
    public function updateCell(string|int $key, string $field, mixed $value): bool
    {
        $key = (string) $key;
        $column = $this->dataTableColumn($field);
        if ($column === null || ! $column->isEditable() || strlen($key) > 64) {
            return false;
        }
        $error = 'cells.'.md5($key.'|'.$field);
        $this->resetErrorBag($error);
        $row = $this->whereKeys($this->query(), [$key])->first();
        if ($row === null) {
            return false;
        }
        $this->authorizeEdit($row, $column);
        if ($column->isNumeric() && is_string($value) && preg_match('/^[+-]?\d+,\d+$/', trim($value)) === 1) {
            $value = str_replace(',', '.', trim($value)); // a decimal comma, as typed in Turkish or German
        }
        $validator = \Illuminate\Support\Facades\Validator::make(
            ['value' => $value === '' ? null : $value],
            ['value' => $column->editRules()],
            [],
            ['value' => $column->headerText()],
        );
        if ($validator->fails()) {
            $this->addError($error, $validator->errors()->first('value'));

            return false;
        }
        $this->saveCell($row, $column, $validator->validated()['value']);
        unset($this->rows); // show the new value

        return true;
    }

    /** Forget the error of a cell whose edit was cancelled. */
    public function cancelEdit(string|int $key, string $field): void
    {
        $this->resetErrorBag('cells.'.md5((string) $key.'|'.$field));
    }

    /**
     * Write an edited value. Default: an Eloquent model's attribute is set
     * and saved (only this column's attribute, never a field named by the
     * browser). Override for query builders or extra logic.
     */
    protected function saveCell(mixed $row, Column $column, mixed $value): void
    {
        if (! $row instanceof \Illuminate\Database\Eloquent\Model) {
            throw new \LogicException('Override saveCell() to save edits of query-builder rows.');
        }
        $qualified = $column->databaseColumn();
        if (str_contains($qualified, '.') && ! str_starts_with($qualified, $row->getTable().'.')) {
            // customers.name on an Order row is another table's column: never written to the order.
            throw new \LogicException("Override saveCell() to save [{$qualified}]: it is not a column of [{$row->getTable()}].");
        }
        $row->setAttribute($column->attributeName(), $value);
        $row->save();
    }

    /**
     * Throws when the user may not edit this row: the model's policy must
     * allow `update`. Without a policy nobody may edit (anyone who can see
     * the table could otherwise change every editable cell), unless
     * `editableWithoutPolicy()` says so. Override for other checks.
     */
    protected function authorizeEdit(mixed $row, Column $column): void
    {
        if (is_object($row) && \Illuminate\Support\Facades\Gate::getPolicyFor($row) !== null) {
            \Illuminate\Support\Facades\Gate::authorize('update', $row);

            return;
        }
        if (! $this->editableWithoutPolicy()) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'Editing needs a policy with update() for '.(is_object($row) ? $row::class : 'these rows').', or editableWithoutPolicy() returning true.'
            );
        }
    }

    /** Whether rows without a model policy may be edited by whoever sees the table. Default `false`. */
    protected function editableWithoutPolicy(): bool
    {
        return false;
    }

    /** The text an editor starts with: decimals as written, dates as `Y-m-d`, date-times as `Y-m-d\TH:i`. */
    protected function editValue(Column $column, mixed $row): string
    {
        $value = data_get($row, $column->attributeName());
        if ($value === null) {
            return '';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format($column->valueType() === 'datetime' ? 'Y-m-d\TH:i' : 'Y-m-d');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }
}
