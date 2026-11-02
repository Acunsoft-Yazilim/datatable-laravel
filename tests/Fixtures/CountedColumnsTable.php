<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\Livewire\WithDataTable;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/** Counts `columns()` calls; its columns depend on component state, as an app's may. */
final class CountedColumnsTable extends Component
{
    use WithDataTable;

    public static int $calls = 0;

    public bool $withCity = false;

    protected function columns(): array
    {
        self::$calls++;

        return array_values(array_filter([
            Column::make('customer')->header('Customer')->sortable()->filterable(),
            $this->withCity ? Column::make('city')->header('City')->sortable()->filterable() : null,
            Column::make('total')->header('Total')->type('decimal')->sortable()->aggregate('sum'),
        ]));
    }

    protected function query(): Builder
    {
        return Order::query();
    }

    protected function selectable(): bool
    {
        return true;
    }

    /** Reads the columns, then changes what they are: the render must see the change. */
    public function addCity(): void
    {
        $this->dataTableColumns();
        $this->withCity = true;
    }
}
