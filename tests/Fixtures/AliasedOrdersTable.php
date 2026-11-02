<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\Livewire\WithDataTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/** Overrides trait methods and calls the trait's own through an alias, as apps do. */
final class AliasedOrdersTable extends Component
{
    use WithDataTable {
        toggleRow as protected traitToggleRow;
        pageSizeOptions as protected traitPageSizeOptions;
    }

    public int $toggles = 0;

    protected function columns(): array
    {
        return [Column::make('customer')->header('Customer')->sortable()];
    }

    protected function query(): Builder
    {
        return Order::query();
    }

    protected function selectable(): bool
    {
        return true;
    }

    protected function pageSizeOptions(): array
    {
        return [2, ...$this->traitPageSizeOptions()];
    }

    public function toggleRow(string|int $key): void
    {
        $this->toggles++;
        $this->traitToggleRow($key);
    }
}
