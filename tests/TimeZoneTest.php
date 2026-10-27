<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Tests;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\DataTableServiceProvider;
use Acunsoft\DataTable\Laravel\QueryApplier;
use Acunsoft\DataTable\Laravel\TableExport;
use Acunsoft\DataTable\Laravel\TableQuery;
use Acunsoft\DataTable\Laravel\TableResponse;
use Acunsoft\DataTable\Laravel\Tests\Fixtures\Order;
use Illuminate\Support\Facades\Route;

/**
 * A day for a datetime column is the day where the user is (`tz`, sent by the
 * browser), written in the application's zone (Europe/Istanbul here), as the
 * rows are stored. Created: Ayşe 23 Oct 14:05, Ali 22 Oct 23:59:59, Can 23 Oct
 * 00:00:00, Iğdır 24 Oct 09:00 (Istanbul time).
 */
final class TimeZoneTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DataTableServiceProvider::class];
    }

    /** @return list<Column> */
    private function columns(): array
    {
        return [
            Column::make('customer')->sortable(),
            Column::make('created')->column('created_at')->type('datetime')->sortable()->filterable(),
            Column::make('day')->column('created_at')->type('date')->filterable(),
        ];
    }

    private function tableQuery(array $filter, ?string $tz = null): TableQuery
    {
        return TableQuery::fromArray(['filters' => [$filter], 'sort' => 'customer:asc', ...($tz === null ? [] : ['tz' => $tz])]);
    }

    /** @return list<string> */
    private function customers(TableQuery $query): array
    {
        return QueryApplier::apply(Order::query(), $query, $this->columns())->pluck('customer')->all();
    }

    /** @return list<mixed> */
    private function bindings(TableQuery $query): array
    {
        return QueryApplier::apply(Order::query(), $query, $this->columns())->getBindings();
    }

    public function test_the_day_is_the_users_day_written_in_the_applications_zone(): void
    {
        $day = ['field' => 'created', 'op' => 'eq', 'value' => '2026-10-23'];
        self::assertSame(['Ayşe Yılmaz', 'Can Öztürk'], $this->customers($this->tableQuery($day)));
        // Tokyo is six hours ahead: its 23 October is 22 Oct 18:00 – 23 Oct 18:00 in Istanbul.
        self::assertSame(['2026-10-22 18:00:00', '2026-10-23 18:00:00'], $this->bindings($this->tableQuery($day, 'Asia/Tokyo')));
        self::assertSame(['Ali Kaya', 'Ayşe Yılmaz', 'Can Öztürk'], $this->customers($this->tableQuery($day, 'Asia/Tokyo')));
        self::assertSame(['2026-10-23 07:00:00', '2026-10-24 07:00:00'], $this->bindings($this->tableQuery($day, 'America/New_York')));
        self::assertSame(['Ayşe Yılmaz'], $this->customers($this->tableQuery($day, 'America/New_York')));
    }

    public function test_every_operator_keeps_its_meaning(): void
    {
        $tz = 'Asia/Tokyo';
        self::assertSame(['2026-10-23 18:00:00'], $this->bindings($this->tableQuery(['field' => 'created', 'op' => 'gt', 'value' => '2026-10-23'], $tz)));
        self::assertSame(['2026-10-22 18:00:00'], $this->bindings($this->tableQuery(['field' => 'created', 'op' => 'gte', 'value' => '2026-10-23'], $tz)));
        self::assertSame(['2026-10-22 18:00:00'], $this->bindings($this->tableQuery(['field' => 'created', 'op' => 'lt', 'value' => '2026-10-23'], $tz)));
        self::assertSame(['2026-10-23 18:00:00'], $this->bindings($this->tableQuery(['field' => 'created', 'op' => 'lte', 'value' => '2026-10-23'], $tz)));
        self::assertSame(['2026-10-19 18:00:00', '2026-10-23 18:00:00'], $this->bindings($this->tableQuery(['field' => 'created', 'op' => 'between', 'value' => ['2026-10-20', '2026-10-23']], $tz)));
    }

    public function test_without_a_zone_or_with_an_unknown_one_nothing_changes(): void
    {
        $day = ['field' => 'created', 'op' => 'eq', 'value' => '2026-10-23'];
        $before = ['2026-10-23 00:00:00', '2026-10-24 00:00:00'];
        self::assertSame($before, $this->bindings($this->tableQuery($day)));
        self::assertSame($before, $this->bindings($this->tableQuery($day, 'Europe/Istanbul')));
        self::assertSame($before, $this->bindings($this->tableQuery($day, 'Mars/Olympus')));
        self::assertSame($before, $this->bindings($this->tableQuery($day, str_repeat('x', 100))));
        self::assertNull($this->tableQuery($day, 'Mars/Olympus')->timeZone);
        self::assertSame('Asia/Calcutta', $this->tableQuery($day, 'Asia/Calcutta')->timeZone); // an old name some browsers send
    }

    public function test_clock_changes_give_a_23_and_a_25_hour_day(): void
    {
        self::assertSame(['2026-03-08 08:00:00', '2026-03-09 07:00:00'], $this->bindings($this->tableQuery(['field' => 'created', 'op' => 'eq', 'value' => '2026-03-08'], 'America/New_York')));
        self::assertSame(['2026-11-01 07:00:00', '2026-11-02 08:00:00'], $this->bindings($this->tableQuery(['field' => 'created', 'op' => 'eq', 'value' => '2026-11-01'], 'America/New_York')));
    }

    public function test_instants_and_date_columns_are_left_alone(): void
    {
        self::assertSame(['2026-10-23 10:00:00'], $this->bindings($this->tableQuery(['field' => 'created', 'op' => 'gt', 'value' => '2026-10-23T10:00:00+03:00'], 'Asia/Tokyo')));
        self::assertSame(['2026-10-23', '2026-10-24'], $this->bindings($this->tableQuery(['field' => 'day', 'op' => 'eq', 'value' => '2026-10-23'], 'Asia/Tokyo')));
    }

    public function test_with_time_zone_sets_or_refuses_a_zone(): void
    {
        $query = $this->tableQuery(['field' => 'created', 'op' => 'eq', 'value' => '2026-10-23'], 'Asia/Tokyo')->withTimeZone('America/New_York');
        self::assertSame(['2026-10-23 07:00:00', '2026-10-24 07:00:00'], $this->bindings($query));
        self::assertNull($query->withTimeZone(null)->timeZone);
        $this->expectException(\InvalidArgumentException::class);
        $query->withTimeZone('Mars/Olympus');
    }

    public function test_the_endpoint_reads_tz_and_timeZone_wins(): void
    {
        Route::get('/orders', fn () => TableResponse::for(Order::query())->columns($this->columns()));
        Route::get('/orders-istanbul', fn () => TableResponse::for(Order::query())->columns($this->columns())->timeZone('Europe/Istanbul'));
        $ask = fn (string $url) => array_column($this->getJson($url.'?sort=customer:asc&filters[0][field]=created&filters[0][op]=eq&filters[0][value]=2026-10-23&tz=Asia/Tokyo')->assertOk()->json('rows'), 'customer');
        self::assertSame(['Ali Kaya', 'Ayşe Yılmaz', 'Can Öztürk'], $ask('/orders'));
        self::assertSame(['Ayşe Yılmaz', 'Can Öztürk'], $ask('/orders-istanbul'));
        $datatables = $this->getJson('/orders?draw=1&start=0&length=10&columns[0][data]=customer&order[0][column]=0&order[0][dir]=asc&filters[0][field]=created&filters[0][op]=eq&filters[0][value]=2026-10-23&tz=Asia/Tokyo')->assertOk();
        self::assertSame(['Ali Kaya', 'Ayşe Yılmaz', 'Can Öztürk'], array_column($datatables->json('data'), 'customer'));
        $this->expectException(\InvalidArgumentException::class);
        TableResponse::for(Order::query())->timeZone('Mars/Olympus');
    }

    public function test_an_export_keeps_the_users_days(): void
    {
        $response = TableExport::for(Order::query())->columns($this->columns())
            ->query($this->tableQuery(['field' => 'created', 'op' => 'eq', 'value' => '2026-10-23'], 'Asia/Tokyo'))
            ->csv('orders.csv');
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();
        self::assertStringContainsString('Ali Kaya', $csv);
        self::assertStringContainsString('Can Öztürk', $csv);
        self::assertStringNotContainsString('Iğdır', $csv);
    }
}
