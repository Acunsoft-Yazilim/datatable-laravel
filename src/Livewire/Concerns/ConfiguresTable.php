<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Livewire\Concerns;

use Acunsoft\DataTable\Laravel\Action;
use Acunsoft\DataTable\Laravel\Column;
use Acunsoft\DataTable\Laravel\TableQuery;
use Acunsoft\DataTable\Laravel\Support\Icons;

/**
 * What a table can override: page sizes, counting, the search engine, layout,
 * texts, selection, export and actions. Each method documents its default.
 *
 * @internal part of {@see \Acunsoft\DataTable\Laravel\Livewire\WithDataTable}; use that trait.
 */
trait ConfiguresTable
{
    /** @return list<int> */
    protected function pageSizeOptions(): array
    {
        return [10, 25, 50, 100];
    }

    /**
     * Rows per page before the user picks another; one of `pageSizeOptions()`.
     * Default: 25 when it is an option, else the first option.
     */
    protected function defaultPageSize(): int
    {
        $sizes = $this->pageSizeOptions();

        return in_array(25, $sizes, true) ? 25 : $sizes[0];
    }

    /**
     * Up to this many matches (default 10,000) the count is exact. It stops
     * after the limit + 1 matches, but when few rows match and no index serves
     * the filter, finding them can still read the whole table: its cost
     * depends on the query, the indexes and the database. Beyond it an exact count gets `countTimeout()`; only when that is
     * not enough the view shows "10,000+". `null` counts everything, always.
     */
    protected function countLimit(): ?int
    {
        return 10_000;
    }

    /**
     * Time an exact count past `countLimit()` may take, in milliseconds
     * (default 250; PostgreSQL, MySQL and MariaDB, other databases show
     * "10,000+"). `null` never counts past the limit.
     */
    protected function countTimeout(): ?int
    {
        return 250;
    }

    /**
     * Answer the search with a search engine instead of `LIKE` over the
     * columns: return the matching keys, e.g. with Laravel Scout
     * `Order::search($search)->take(10_000)->keys()`. Filters, sorting,
     * paging, counts, totals, bulk actions and exports stay in the database,
     * limited to those keys. `null` (default): search the columns.
     *
     * @return iterable<int|string>|null
     */
    protected function searchEngine(string $search): ?iterable
    {
        return null;
    }

    /** @var array<string, list<int|string>> the engine's keys per search text, for this request */
    private array $searchEngineKeys = [];

    private function withEngine(TableQuery $query): TableQuery
    {
        if ($query->search === '') {
            return $query;
        }
        if (! array_key_exists($query->search, $this->searchEngineKeys)) {
            $keys = $this->searchEngine($query->search);
            $this->searchEngineKeys[$query->search] = $keys === null ? null : (is_array($keys) ? $keys : iterator_to_array($keys, false));
        }
        $keys = $this->searchEngineKeys[$query->search];

        return $keys === null ? $query : $query->withSearchKeys($keys, $this->keyName());
    }

    /**
     * Keyset paging: a page continues after the last row of the page before,
     * through an index, instead of reading and skipping `OFFSET` rows (on
     * 5,000,000 PostgreSQL rows page 100,001 took 55.5 ms with the count,
     * against 3,898 ms by `OFFSET`; other tables and databases: measure). No
     * result window applies. Pages are reached one after another (the pager
     * offers those seen so far and the next one). Mark sort columns that may
     * hold NULL with `Column::nullable()`.
     */
    protected function cursorPaging(): bool
    {
        return false;
    }

    /** Pages start at most this many rows deep (default 100,000; `null`: no limit). */
    protected function maxResultWindow(): ?int
    {
        return 100_000;
    }

    /**
     * Where the controls go: a bar above and one below the table, each with a
     * start and an end side. Return only the slots you change; the others keep
     * their defaults: `topStart` page size and search, `topEnd` filters, column
     * menu and tools, `bottomStart` info, `bottomEnd` pages. A slot is a list of features:
     * `pageSize`, `search`, `filters`, `columns`, `tools` (export, print and import menu),
     * `info`, `paging`, or the name of your own Blade view, which is included
     * with the table's variables (`$rows`, `$columns`, …). `[]` empties a slot.
     *
     * @return array<'topStart'|'topEnd'|'bottomStart'|'bottomEnd', list<string>>
     */
    protected function tableLayout(): array
    {
        return [];
    }

    /**
     * The numbered pager: pages around the current one (`siblings`), pages
     * always shown at both ends (`boundaries`) and first/last buttons.
     *
     * @return array{siblings: int, boundaries: int, firstLast: bool}
     */
    protected function pagingOptions(): array
    {
        return ['siblings' => 1, 'boundaries' => 1, 'firstLast' => false];
    }

    /**
     * Narrow screens: `cards` (default) shows each row as a card; `scroll`
     * keeps the table and scrolls it sideways; `collapse` hides lower-priority
     * columns (`Column::priority()`) behind a button that opens them below the
     * row; `modal` opens every value of the row in a dialog instead.
     */
    protected function responsive(): string
    {
        return 'cards';
    }

    /** Density: `sm` compact, `md` (default) or `lg` spacious. */
    protected function tableSize(): string
    {
        return 'md';
    }

    /** Row actions shown as buttons in the row; the rest go into a "⋮" menu. `null`: all in the row. */
    protected function maxInlineActions(): ?int
    {
        return null;
    }

    /**
     * A child row under each row (DataTables child rows): the name of a Blade
     * view, rendered with `$row` and `$key` once its ⊕ button opens it, so it
     * may load relations or run queries for that row only (shown as
     * "Loading…" meanwhile). With `responsive()` `modal` it opens in the
     * dialog. `null` (default): no child rows.
     */
    protected function detailView(): ?string
    {
        return null;
    }

    /**
     * Drag the edge of a header (or use ←/→ on it, Delete to reset) to set a
     * column's width. Widths live in `$columnWidths` for the component's life.
     */
    protected function resizableColumns(): bool
    {
        return false;
    }

    /**
     * Drag a header onto another to move the column; the column menu gets
     * up/down buttons for the keyboard. The order lives in `$columnOrder`.
     */
    protected function reorderableColumns(): bool
    {
        return false;
    }

    /** Shift+click adds a second sort column. Return false to always sort by one column. */
    protected function multiSort(): bool
    {
        return true;
    }

    /**
     * Keep the header row at the top of the window while the page scrolls
     * (DataTables `fixedHeader`). Below a fixed top bar set `--adt-sticky-top`.
     */
    protected function stickyHeader(): bool
    {
        return false;
    }

    /**
     * Columns that stay in place while the table scrolls sideways (DataTables
     * `fixedColumns`): `['start' => 1, 'end' => 1]` keeps the first data
     * column (with the checkbox and ⊕ before it) and the last cell (the
     * actions, when there are any). Offsets are measured in the browser
     * (Alpine); the phone card layout does not pin.
     *
     * @return array{start?: int, end?: int}
     */
    protected function fixedColumns(): array
    {
        return [];
    }

    /**
     * A fixed table height to scroll inside, the header staying in place
     * (DataTables `scrollY`): a CSS length such as `60vh` or `480px`; `null` off.
     */
    protected function scrollY(): ?string
    {
        return null;
    }

    /**
     * Where the filter fields open: `dropdown` under the button (default),
     * `drawer` from the side of the window, `modal` centred. For your own
     * drawer or modal, leave `filters` out of `tableLayout()` and put
     * `@include('acun-datatable::livewire.filter-panel')` in your own view.
     */
    protected function filterPanel(): string
    {
        return 'dropdown';
    }

    /**
     * Text, icon and classes of the toolbar buttons, by feature (`columns`,
     * `filters`, `tools`): `['filters' => ['label' => 'Süz', 'icon' => 'filter',
     * 'iconOnly' => true, 'class' => 'btn btn-light']]`. `icon` is a built-in
     * name, SVG path data (24×24) or `false` for none.
     *
     * @return array<string, array{label?: string, icon?: string|false, iconOnly?: bool, class?: string}>
     */
    protected function toolbarButtons(): array
    {
        return [];
    }

    /**
     * The look of one toolbar button for the views: its text, icon path (or
     * null), whether only the icon shows, and its classes.
     *
     * @return array{label: string, path: ?string, iconOnly: bool, class: string}
     */
    protected function toolbarButton(string $feature, string $label, string $icon): array
    {
        $custom = $this->toolbarButtons()[$feature] ?? [];
        $chosen = $custom['icon'] ?? $icon;
        $path = $chosen === false ? null : Icons::path((string) $chosen);
        if ($chosen !== false && $path === null) {
            throw new \InvalidArgumentException("Toolbar button [{$feature}]: unknown icon [{$chosen}].");
        }
        $iconOnly = (bool) ($custom['iconOnly'] ?? false);

        return [
            'label' => (string) ($custom['label'] ?? $label),
            'path' => $path,
            'iconOnly' => $iconOnly,
            'class' => trim(($iconOnly ? 'adt-icon-only ' : '').($custom['class'] ?? '')),
        ];
    }

    /**
     * The address of a TableImport (`Route::acunImport()`), e.g.
     * `route('orders.import')` or `url('import/orders')`: the tools menu gets
     * "Import…", which opens the import dialog of @acunsoft/datatable-import
     * (load its `acun-datatable-import.min.js` and `styles.css` on the page);
     * the table is refreshed when rows changed. Null (default): no such item.
     */
    protected function importEndpoint(): ?string
    {
        return null;
    }

    /** Show "Print" in the tools menu: prints only the table (the rows on screen), not the page around it. */
    protected function printable(): bool
    {
        return false;
    }

    /**
     * Texts for this table only, by the keys of the package's language file
     * (`lang/en/table.php`), e.g. a noun that fits the rows:
     * `['page_info' => ':total personel üyesinden :from ile :to arası gösteriliyor']`.
     * Use `__()` inside for several languages; `a|b` gives singular and plural.
     *
     * @return array<string, string>
     */
    protected function texts(): array
    {
        return [];
    }

    /**
     * A text of the views: the table's own from `texts()`, else the package's.
     * Pass a number as the second argument for singular/plural texts.
     *
     * @param int|array<string, mixed> $count the number for plural texts, or the replacements
     * @param array<string, mixed> $replace
     */
    protected function tableText(string $key, int|array $count = [], array $replace = []): string
    {
        $line = $this->texts()[$key] ?? 'acun-datatable::table.'.$key;
        if (is_array($count)) {
            return (string) __($line, $count);
        }

        return trans_choice($line, $count, $replace);
    }

    /** Explicitly selected keys are limited; "all matching" selection has no limit. */
    protected function maxSelected(): int
    {
        return 1000;
    }

    /** Off by default: return true to show the selection column. */
    protected function selectable(): bool
    {
        return false;
    }

    protected function caption(): ?string
    {
        return null;
    }

    /** @return list<string> export buttons to show: `'xlsx'` and/or `'csv'`. None by default. */
    protected function exportFormats(): array
    {
        return [];
    }

    /** File name without extension. */
    protected function exportFilename(): string
    {
        return 'export';
    }

    protected function exportLimit(): int
    {
        return 100_000;
    }

    /** CSV delimiter: `;` for Turkish, German, French and Spanish, which Excel there opens directly; else `,`. */
    protected function exportDelimiter(): string
    {
        // Excel in Turkish, German, French and Spanish (decimal comma) splits CSV at ';' (also tr_TR, de-AT …).
        return in_array(strtolower(preg_split('/[-_]/', app()->getLocale())[0]), ['tr', 'de', 'fr', 'es'], true) ? ';' : ',';
    }

    /** @return list<Action> buttons in each row; handlers receive the row */
    protected function rowActions(): array
    {
        return [];
    }

    /** @return list<Action> buttons for the selected rows; handlers receive `selectedQuery()` */
    protected function bulkActions(): array
    {
        return [];
    }

    /** The key column for a query builder (Eloquent uses the model key). */
    protected function keyName(): string
    {
        return 'id';
    }

    /** The row's name for screen readers ("Select Ayşe"): the first column's text by default. */
    protected function rowLabel(mixed $row): string
    {
        foreach ($this->dataTableColumns() as $column) {
            $text = \Acunsoft\DataTable\Laravel\Support\Format::cell($column, $row);
            if (trim($text) !== '') {
                return $text;
            }
        }

        return $this->rowKey($row);
    }

    protected function rowKey(mixed $row): string
    {
        $key = is_object($row) && method_exists($row, 'getKey') ? $row->getKey() : data_get($row, $this->keyName());

        return (string) $key;
    }
}
