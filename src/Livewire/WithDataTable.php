<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Livewire;

use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\InvalidTableQuery;
use Acunsoft\DataTable\Laravel\Livewire\Concerns\ConfiguresTable;
use Acunsoft\DataTable\Laravel\Livewire\Concerns\EditsCells;
use Acunsoft\DataTable\Laravel\Livewire\Concerns\ExportsRows;
use Acunsoft\DataTable\Laravel\Livewire\Concerns\ManagesColumns;
use Acunsoft\DataTable\Laravel\Livewire\Concerns\RunsActions;
use Acunsoft\DataTable\Laravel\Livewire\Concerns\RunsTableTasks;
use Acunsoft\DataTable\Laravel\Livewire\Concerns\SelectsRows;
use Acunsoft\DataTable\Laravel\Livewire\Concerns\ShowsChildRows;
use Acunsoft\DataTable\Laravel\Livewire\Concerns\SortsAndFilters;
use Acunsoft\DataTable\Laravel\QueryApplier;
use Acunsoft\DataTable\Laravel\Support\PageItems;
use Acunsoft\DataTable\Laravel\TablePage;
use Acunsoft\DataTable\Laravel\TableQuery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

/**
 * A server-side data table for a Livewire component.
 *
 * Declare `columns()` and `query()`; the trait keeps search, sorting, filters
 * and page size in the URL (the same names as the HTTP wire format: `search`,
 * `sort=field:dir,…`, `filters[i][field|op|value]`, `pageSize`, `page`), applies
 * them with QueryApplier and renders `acun-datatable::livewire.table` unless
 * the component has its own `render()`.
 *
 * Every value from the browser is untrusted: URL state that does not match the
 * declared columns is dropped instead of failing the page, only declared
 * columns reach the SQL, and selected keys are only ever looked up inside
 * `query()`, so selection cannot reach rows outside the developer's scope.
 *
 * One table per page: the URL names are not prefixed.
 */
trait WithDataTable
{
    use WithPagination;
    use ConfiguresTable;
    use ManagesColumns;
    use SortsAndFilters;
    use EditsCells;
    use ShowsChildRows;
    use SelectsRows;
    use ExportsRows;
    use RunsTableTasks;
    use RunsActions;

    #[Url(except: '')]
    public string $search = '';

    /** `field:dir,…`, e.g. `total:desc,customer:asc`. */
    #[Url(except: '')]
    public string $sort = '';

    /** @var array<int, mixed> list of `['field' => …, 'op' => …, 'value' => …]` */
    #[Url(except: [])]
    public array $filters = [];

    /** Rows per page; in the URL as `pageSize` while it differs from `defaultPageSize()`. */
    public int $pageSize = 25;

    /** @var array<int, mixed> IDs of hidden columns (presentation only: they still sort, filter and search) */
    #[Url(as: 'hidden', except: [])]
    public array $hiddenColumns = [];

    /** @var array<int, mixed> keys of explicitly selected rows */
    public array $selected = [];

    /** Every row matching the current search and filters, except `$excluded`. */
    #[Locked]
    public bool $allMatching = false;

    /** @var list<string> */
    #[Locked]
    public array $excluded = [];

    /** @var array<int, mixed> keys of the rows whose child row (`detailView()`) is open */
    public array $openDetails = [];

    /** @var array<int, mixed> column IDs in display order (`reorderableColumns()`); the rest follow in definition order */
    public array $columnOrder = [];

    /** @var array<string, mixed> widths in pixels per column ID (`resizableColumns()`) */
    public array $columnWidths = [];

    /** The queued task (large export, queued bulk action) this table waits for; see checkTableTask(). */
    #[Locked]
    public ?string $tableTask = null;

    /** @var array<int, string> cursor paging: the cursor that opens each page seen so far, by page number */
    #[Locked]
    public array $cursors = [];

    /** @return iterable<Column> */
    abstract protected function columns(): iterable;

    /** The rows this user may see; everything the table does stays inside it. */
    abstract protected function query(): EloquentBuilder|QueryBuilder;

    /** Livewire: `pageSize` in the URL, left out while it is the default. */
    protected function queryStringWithDataTable(): array
    {
        return ['pageSize' => ['except' => $this->defaultPageSize(), 'history' => false]];
    }

    public function render(): View
    {
        return view('acun-datatable::livewire.table');
    }

    #[Computed]
    public function rows(): LengthAwarePaginator
    {
        $query = $this->dataTableQuery();
        $page = fn () => QueryApplier::paginate($this->query(), $this->dataTableQuery(), $this->dataTableColumns(), $this->countLimit(), $this->maxResultWindow(), $this->countTimeout(), $this->keyName());
        try {
            $rows = $page();
        } catch (InvalidTableQuery $error) {
            if (! $query->cursorPaging || ! isset($error->errors['cursor'])) {
                throw $error;
            }
            // A cursor of another sort or rows (changed from outside): start again on page 1.
            $this->cursors = [];
            $this->setPage(1);
            $query = $this->dataTableQuery();
            $rows = $page();
        }
        if ($query->cursorPaging) {
            // Remember the cursors up to this page and the one that opens the next.
            $known = array_filter($this->cursors, fn ($cursor, $number) => is_int($number) && $number <= $query->page, ARRAY_FILTER_USE_BOTH);
            if ($rows->nextCursor() !== null) {
                $known[$query->page + 1] = $rows->nextCursor();
            }
            $this->cursors = $known;
            if ($query->page !== (int) $this->getPage()) {
                $this->setPage($query->page);
            }

            return $rows;
        }
        if ($rows->isEmpty() && $query->page > 1 && $rows->total() > 0) {
            // The page is past the end (rows were deleted, or the URL was edited): show the last one.
            $this->setPage($rows->totalIsExact() ? $rows->lastPage() : 1);
            $rows = $page();
        }

        return $rows;
    }

    /**
     * Footer totals of the columns with `aggregate()`, over every matching row;
     * empty while the row count is a lower bound (a sum over millions of rows
     * would slow every request).
     *
     * @return array<string, string|int|float|null>
     */
    #[Computed]
    public function totals(): array
    {
        if (! $this->rows->totalIsExact()) {
            return [];
        }

        return QueryApplier::aggregates($this->query(), $this->dataTableQuery(), $this->dataTableColumns());
    }

    /** @var list<Column>|null `columns()` while the view renders, which asks for them many times */
    private ?array $renderedColumns = null;

    private bool $renderingTable = false;

    /** @return list<Column> */
    protected function dataTableColumns(): array
    {
        if ($this->renderedColumns !== null) {
            return $this->renderedColumns;
        }
        $columns = [];
        foreach ($this->columns() as $column) {
            if (! $column instanceof Column) {
                throw new \UnexpectedValueException(static::class.'::columns() must return Column instances.');
            }
            $columns[] = $column;
        }
        if ($this->renderingTable) {
            $this->renderedColumns = $columns;
        }

        return $columns;
    }

    /**
     * One `columns()` call per render, however often the view asks: columns
     * that read options from the database query once. Outside the render
     * (actions, updates) they are built on every call, so a `columns()` that
     * depends on component state always sees the current state.
     */
    public function renderingWithDataTable(): void
    {
        $this->renderingTable = true;
        $this->renderedColumns = null;
    }

    public function renderedWithDataTable(): void
    {
        $this->renderingTable = false;
        $this->renderedColumns = null;
    }

    /** @return list<array{field: string, direction: 'asc'|'desc'}> */
    protected function sorting(): array
    {
        return $this->dataTableQuery()->sorting;
    }

    /** Apply `Column::hidden()` unless the URL already says which columns are hidden. */
    public function mountWithDataTable(): void
    {
        if (! request()->query->has('pageSize')) {
            $this->pageSize = $this->defaultPageSize();
        }
        if ($this->hiddenColumns === []) {
            $this->hiddenColumns = array_values(array_map(
                fn (Column $column) => $column->id(),
                array_filter($this->dataTableColumns(), fn (Column $column) => $column->isHiddenByDefault()),
            ));
        }
    }

    /**
     * Shown while a lazy table (`#[Lazy]` on the component, or
     * `<livewire:orders lazy />`) loads: the header and placeholder rows that
     * shimmer, so the page does not jump when the rows arrive. Define your own
     * `placeholder()` to replace it.
     *
     * @param  array<string, mixed>  $params
     */
    public function placeholder(array $params = []): View
    {
        try {
            $columns = $this->dataTableVisibleColumns();
        } catch (\Throwable) {
            $columns = []; // columns() that needs mount(): bars only
        }

        return view('acun-datatable::livewire.placeholder', [
            'columns' => $columns,
            'count' => min(max($this->defaultPageSize(), 1), 10),
            'selectable' => $this->selectable(),
            'size' => in_array($this->tableSize(), ['sm', 'md', 'lg'], true) ? $this->tableSize() : 'md',
            'loading' => $this->tableText('loading'),
        ]);
    }

    // Internals ------------------------------------------------------------

    /** The current state as a validated query; parts that do not fit the columns are dropped. */
    protected function dataTableQuery(): TableQuery
    {
        $sizes = $this->pageSizeOptions();
        $max = max($sizes);
        $pageSize = in_array($this->pageSize, $sizes, true) ? $this->pageSize : $this->defaultPageSize();

        $sort = [];
        foreach ($this->sort === '' ? [] : explode(',', $this->sort) as $part) {
            [$field, $direction] = array_pad(explode(':', $part, 2), 2, '');
            $column = $this->dataTableColumn($field);
            if ($column !== null && $column->isSortable() && in_array($direction, ['asc', 'desc'], true) && ! isset($sort[$field])) {
                $sort[$field] = "{$field}:{$direction}";
            }
        }

        $filters = [];
        foreach (array_values($this->filters) as $rule) {
            if (count($filters) < TableQuery::MAX_FILTERS && $this->validFilter($rule)) {
                $filters[] = $rule;
            }
        }

        $page = $this->getPage();
        $page = is_int($page) || (is_string($page) && ctype_digit($page)) ? (int) $page : 1;

        if ($this->cursorPaging()) {
            // Only pages a known cursor leads to; a page from an edited URL starts over.
            $page = $page > 1 && is_string($this->cursors[$page] ?? null) ? $page : 1;

            return $this->withEngine(TableQuery::fromArray([
                'page' => $page,
                'pageSize' => $pageSize,
                'sort' => implode(',', array_slice($sort, 0, TableQuery::MAX_SORT_RULES)),
                'filters' => $filters,
                'search' => mb_substr($this->search, 0, TableQuery::MAX_TEXT),
                'paging' => 'cursor',
                'cursor' => $page > 1 ? $this->cursors[$page] : null,
            ], $max, $pageSize));
        }

        return $this->withEngine(TableQuery::fromArray([
            'page' => min(max($page, 1), TableQuery::MAX_PAGE, $this->maxResultWindow() === null ? TableQuery::MAX_PAGE : max(1, intdiv($this->maxResultWindow() - 1, $pageSize) + 1)),
            'pageSize' => $pageSize,
            'sort' => implode(',', array_slice($sort, 0, TableQuery::MAX_SORT_RULES)),
            'filters' => $filters,
            'search' => mb_substr($this->search, 0, TableQuery::MAX_TEXT),
        ], $max, $pageSize));
    }

    /**
     * What the info text and the pager show for a page of rows.
     *
     * @return array{from: int, to: int, items: list<int|null>, page: int, last: ?int, previous: bool, next: bool}
     */
    protected function pagerState(TablePage $rows): array
    {
        $options = $this->pagingOptions() + ['siblings' => 1, 'boundaries' => 1];
        $size = $rows->perPage();
        $page = $rows->currentPage();
        if ($this->cursorPaging()) {
            // Only pages a cursor leads to: those seen so far and the next one.
            $pages = max([$page, 1, ...array_keys($this->cursors)]);
            $from = $rows->isEmpty() ? 0 : ($page - 1) * $size + 1;

            return [
                'from' => $from,
                'to' => $rows->isEmpty() ? 0 : $from + $rows->count() - 1,
                'items' => PageItems::make($page, $pages, (int) $options['siblings'], (int) $options['boundaries']),
                'page' => $page,
                'last' => null,
                'previous' => $page > 1,
                'next' => isset($this->cursors[$page + 1]),
            ];
        }
        $window = $this->maxResultWindow() === null ? TableQuery::MAX_PAGE : max(1, intdiv($this->maxResultWindow() - 1, $size) + 1);
        // A lower-bound total still proves that its pages exist; past the window none can be opened.
        $pages = max($page, min((int) ceil(max($rows->total(), 1) / $size), $window, TableQuery::MAX_PAGE));
        $from = $rows->isEmpty() ? 0 : ($page - 1) * $size + 1;

        return [
            'from' => $from,
            'to' => $rows->isEmpty() ? 0 : $from + $rows->count() - 1,
            'items' => PageItems::make($page, $pages, (int) $options['siblings'], (int) $options['boundaries']),
            'page' => $page,
            'last' => $rows->totalIsExact() ? $pages : null,
            'previous' => $page > 1,
            'next' => $page < $window && $rows->hasMorePages(),
        ];
    }

    /** @return array<string, list<string>> */
    protected function dataTableLayout(): array
    {
        $custom = $this->tableLayout();
        $layout = [];
        foreach ([
            'topStart' => ['pageSize', 'search'],
            'topEnd' => ['filters', 'columns', 'tools'],
            'bottomStart' => ['info'],
            'bottomEnd' => ['paging'],
        ] as $slot => $default) {
            $features = array_key_exists($slot, $custom) ? $custom[$slot] : $default;
            $layout[$slot] = array_values(array_filter(is_array($features) ? $features : [$features], 'is_string'));
        }

        return $layout;
    }

    private function sortChanged(): void
    {
        $this->cursors = [];
        $this->resetPage();
    }

    protected function tableChanged(): void
    {
        $this->cursors = []; // other rows: the cursors no longer apply
        $this->resetPage();
        $this->clearSelection();
    }

    private function validFilter(mixed $rule): bool
    {
        if (! is_array($rule) || ! is_string($rule['field'] ?? null)) {
            return false;
        }
        $column = $this->dataTableColumn($rule['field']);
        if ($column === null || ! $column->isFilterable()) {
            return false;
        }
        try {
            $query = TableQuery::fromArray(['filters' => [$rule]]);
            if ($query->filters === []) {
                return false; // a blank contains/startsWith constrains nothing
            }
            QueryApplier::apply($this->query(), $query, [$column]); // checks the value for the column type; nothing runs
        } catch (InvalidTableQuery) {
            return false;
        }

        return true;
    }

    private function dataTableColumn(string $id): ?Column
    {
        foreach ($this->dataTableColumns() as $column) {
            if ($column->id() === $id) {
                return $column;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function selectedKeys(): array
    {
        return self::distinctKeys($this->selected, $this->maxSelected());
    }

    /**
     * The first `$max` distinct keys of a list the browser may have set: one pass with a
     * keyed set, stopping at the limit, so a huge list costs no more than `$max` keys.
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function distinctKeys(array $values, int $max): array
    {
        $keys = [];
        foreach ($values as $key) {
            if (count($keys) >= $max) {
                break;
            }
            if ((is_string($key) || is_int($key)) && strlen((string) $key) <= 64) {
                $keys[(string) $key] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }

    /** Lists the browser may set are trimmed once, when set, not on every read. */
    public function updatedWithDataTable(string $name): void
    {
        match (explode('.', $name)[0]) {
            'selected' => $this->selected = $this->selectedKeys(),
            'openDetails' => $this->openDetails = $this->openDetailKeys(),
            'sort' => $this->sortChanged(), // set directly (wire:model, $wire.set), as sortBy() does
            default => null,
        };
    }

    /** @return list<string> */
    private function pageKeys(): array
    {
        return array_map(fn ($row) => $this->rowKey($row), $this->rows->items());
    }

    /** @return list<array{field: string, op: string, value: mixed}> */
    private function wireFilters(TableQuery $query): array
    {
        return array_map(fn (array $rule) => ['field' => $rule['field'], 'op' => $rule['operator'], 'value' => $rule['value']], $query->filters);
    }

    /** @param list<string> $keys */
    private function whereKeys(EloquentBuilder|QueryBuilder $builder, array $keys, bool $not = false): EloquentBuilder|QueryBuilder
    {
        if ($builder instanceof EloquentBuilder) {
            return $not ? $builder->whereKeyNot($keys) : $builder->whereKey($keys);
        }

        return $not ? $builder->whereNotIn($this->keyName(), $keys) : $builder->whereIn($this->keyName(), $keys);
    }
}
