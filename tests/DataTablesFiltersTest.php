<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\QueryApplier;
use Acunsoft\DataTable\Laravel\TableQuery;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;

/** DataTables requests from this package's client carry each filter twice: as filters[i] and as its column's search. */
final class DataTablesFiltersTest extends TestCase
{
    /** @return list<Column> */
    private function columns(): array
    {
        return [Column::make('customer')->sortable()->filterable(), Column::make('active')->type('boolean')->filterable()];
    }

    /** @param array<string, mixed> $input @return list<string> */
    private function customers(array $input): array
    {
        return QueryApplier::apply(Order::query()->orderBy('id'), TableQuery::fromDataTables($input), $this->columns())->pluck('customer')->all();
    }

    public function test_filters_win_over_their_column_search_copies(): void
    {
        $input = [
            'draw' => '1', 'start' => '0', 'length' => '10',
            'columns' => [['data' => 'customer', 'search' => ['value' => '']], ['data' => 'active', 'search' => ['value' => 'true']]],
            'filters' => [['field' => 'active', 'op' => 'eq', 'value' => 'true']],
        ];
        self::assertSame(['active', 'eq', 'true'], array_values(TableQuery::fromDataTables($input)->filters[0]));
        self::assertCount(1, TableQuery::fromDataTables($input)->filters);
        self::assertSame(Order::query()->where('active', true)->orderBy('id')->pluck('customer')->all(), $this->customers($input));
    }

    public function test_start_skips_its_rows_also_when_it_is_no_whole_number_of_pages(): void
    {
        $input = ['draw' => '2', 'start' => '3', 'length' => '2', 'columns' => [['data' => 'customer'], ['data' => 'active']]];
        $page = QueryApplier::paginate(Order::query()->orderBy('id'), TableQuery::fromDataTables($input), $this->columns());
        self::assertSame(Order::query()->orderBy('id')->skip(3)->take(2)->pluck('customer')->all(), collect($page->items())->pluck('customer')->all());
    }

    public function test_a_column_search_is_contains_without_filters_as_datatables_sends_it(): void
    {
        $input = ['draw' => '1', 'start' => '0', 'length' => '10', 'columns' => [['data' => 'customer', 'search' => ['value' => 'Ka']], ['data' => 'active', 'search' => ['value' => '']]]];
        self::assertSame(['Ali Kaya'], $this->customers($input));
    }
}
