<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\QueryApplier;
use Acunsoft\DataTable\Laravel\TableExport;
use Acunsoft\DataTable\Laravel\TableQuery;
use Acunsoft\DataTable\Laravel\TableResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A query builder has no model key: the key given is the last sort tie-breaker, so equal values never swap between pages. */
final class QueryBuilderKeyTest extends TestCase
{
    /** @return list<Column> */
    private function columns(): array
    {
        return [Column::make('customer')->sortable(), Column::make('city')->sortable()];
    }

    /** @return list<string> the ORDER BY of every select run by $run */
    private function orders(callable $run): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $orders = [];
        foreach (DB::getQueryLog() as $entry) {
            if (preg_match('/order by (.+?)(?: limit| offset|$)/', $entry['query'], $match) === 1) {
                $orders[] = $match[1];
            }
        }
        DB::disableQueryLog();

        return $orders;
    }

    public function test_paging_a_query_builder_orders_by_its_key_last(): void
    {
        $query = TableQuery::fromArray(['sort' => 'city:desc', 'pageSize' => 2]);
        self::assertSame(['"city" desc, "id" desc'], $this->orders(fn () => QueryApplier::paginate(DB::table('orders'), $query, $this->columns(), key: 'id')));
        self::assertSame(['"city" desc'], $this->orders(fn () => QueryApplier::paginate(DB::table('orders'), $query, $this->columns()))); // no key given: as before
        self::assertSame(['"id" asc'], $this->orders(fn () => QueryApplier::paginate(DB::table('orders'), TableQuery::fromArray(['pageSize' => 2]), $this->columns(), key: 'id')));
    }

    public function test_table_response_and_export_take_the_key(): void
    {
        $request = Request::create('/orders', 'GET', ['sort' => 'city:asc', 'pageSize' => 2]);
        self::assertSame(['"city" asc, "id" asc'], $this->orders(fn () => TableResponse::for(DB::table('orders'))->columns($this->columns())->key('id')->toResponse($request)));
        $export = TableExport::for(DB::table('orders'))->columns($this->columns())->key('id')->query(TableQuery::fromArray(['sort' => 'city:asc']));
        $orders = $this->orders(fn () => (fn () => iterator_to_array($this->rows(), false))->call($export));
        self::assertSame('"city" asc, "id" asc', $orders[array_key_last($orders)]); // the chunks read in a stable order
    }
}
