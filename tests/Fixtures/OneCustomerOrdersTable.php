<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests\Fixtures;

/** QueuedOrdersTable limited by a model of its own (a team, a customer …): `query()` needs it in the queue too. */
final class OneCustomerOrdersTable extends \Livewire\Component
{
    use \Acunsoft\DataTable\Laravel\Livewire\WithDataTable;

    public ?Order $only = null;

    public function mount(?Order $only = null): void
    {
        $this->only = $only;
    }

    protected function columns(): array
    {
        return [\Acunsoft\DataTable\Laravel\Column::make('customer')->header('Customer')];
    }

    protected function query(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query()->when($this->only, fn ($query) => $query->where('customer', $this->only->customer));
    }

    protected function exportFormats(): array
    {
        return ['csv'];
    }

    protected function exportLimit(): int
    {
        return 1;
    }

    protected function queuedExports(): bool
    {
        return true;
    }
}
