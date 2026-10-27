<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\InvalidTableQuery;
use Acunsoft\DataTable\Laravel\QueryApplier;
use Acunsoft\DataTable\Laravel\TableExport;
use Acunsoft\DataTable\Laravel\TableQuery;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Counts of queries whose rows depend on their columns: distinct, having, union. */
final class DistinctCountTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id');
            $table->string('product');
        });
        foreach (Order::query()->pluck('id') as $id) {
            foreach (['Kalem', 'Defter', 'Silgi'] as $product) { // three lines per order
                DB::table('order_lines')->insert(['order_id' => $id, 'product' => $product]);
            }
        }
    }

    /** @return list<Column> */
    private function columns(): array
    {
        return [Column::make('customer')->sortable()->filterable(), Column::make('total')->type('decimal')->sortable()];
    }

    /** The five orders that have lines, once each: a join with distinct(). */
    private function ordersWithLines(): Builder
    {
        return Order::query()->select('orders.*')->join('order_lines', 'order_lines.order_id', '=', 'orders.id')->distinct();
    }

    public function test_a_distinct_join_counts_its_distinct_rows_with_and_without_a_limit(): void
    {
        self::assertCount(5, $this->ordersWithLines()->get()); // what the table shows
        self::assertSame([5, true], QueryApplier::count($this->ordersWithLines()));
        self::assertSame([5, true], QueryApplier::count($this->ordersWithLines(), null));
        self::assertSame([3, false], QueryApplier::count($this->ordersWithLines(), 3));
        self::assertSame([5, true], QueryApplier::count($this->ordersWithLines()->toBase()));
    }

    public function test_every_page_of_a_distinct_join_is_reachable(): void
    {
        $page = fn (int $number, ?int $limit = 10_000) => QueryApplier::paginate($this->ordersWithLines(), TableQuery::fromArray(['page' => $number, 'pageSize' => 2, 'sort' => 'total:asc']), $this->columns(), $limit);
        self::assertSame(5, $page(1)->total());
        self::assertTrue($page(1)->totalIsExact());
        $seen = [];
        foreach ([1, 2, 3] as $number) {
            $seen = [...$seen, ...array_map(fn ($order) => $order->customer, $page($number)->items())];
        }
        self::assertSame(['Deniz Şahin', 'Ali Kaya', 'Can Öztürk', 'Ayşe Yılmaz', 'Iğdır Ticaret'], $seen);
        self::assertSame(5, $page(1, null)->total());
    }

    public function test_having_and_union_queries_are_counted_with_their_columns(): void
    {
        $cities = Order::query()->selectRaw('city, count(*) as orders_in_city')->groupBy('city')->having('orders_in_city', '>=', 1);
        self::assertSame([5, true], QueryApplier::count(clone $cities));
        self::assertSame([5, true], QueryApplier::count(clone $cities, null));

        $union = DB::table('orders')->select('customer')->where('total', '<', 100)
            ->union(DB::table('orders')->select('customer')->where('total', '>', 1000));
        self::assertCount(4, $union->get());
        self::assertSame([4, true], QueryApplier::count(clone $union));
        self::assertSame([4, true], QueryApplier::count(clone $union, null));
    }

    public function test_plain_queries_still_count_the_cheap_way(): void
    {
        DB::enableQueryLog();
        self::assertSame([5, true], QueryApplier::count(Order::query()));
        self::assertStringContainsString('1 as adt_match', DB::getQueryLog()[0]['query']);
    }

    public function test_an_export_counts_a_distinct_join_and_stops_after_its_limit(): void
    {
        $export = fn (int $limit) => TableExport::for($this->ordersWithLines())->columns($this->columns())->limit($limit);
        self::assertSame(200, $export(5)->csv('orders.csv')->getStatusCode()); // 5 distinct rows: within a limit of 5

        DB::enableQueryLog();
        try {
            $export(4)->csv('orders.csv');
            self::fail('5 rows are more than 4');
        } catch (InvalidTableQuery $error) {
            self::assertStringContainsString('export_too_large', $error->getMessage());
        }
        $counts = array_values(array_filter(DB::getQueryLog(), fn (array $entry) => str_contains($entry['query'], 'count(')));
        self::assertNotEmpty($counts);
        self::assertStringContainsString('limit 5', strtolower($counts[0]['query'])); // limit + 1: the count stops there
    }
}
