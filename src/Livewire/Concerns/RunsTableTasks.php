<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Livewire\Concerns;

use Acunsoft\DataTable\Laravel\TableExport;
use Acunsoft\DataTable\Laravel\TableQuery;
use Acunsoft\DataTable\Laravel\Support\TableTasks;

/**
 * Queued tasks (large exports, queued bulk actions) and the polling for them.
 *
 * @internal part of {@see \Acunsoft\DataTable\Laravel\Livewire\WithDataTable}; use that trait.
 */
trait RunsTableTasks
{
    /**
     * Exports past `exportLimit()` rows are prepared by a queued job instead
     * of refused; the table offers a download link once the file is ready
     * (signed, for the user who asked, deleted after download). Needs a queue
     * worker. Default false.
     */
    protected function queuedExports(): bool
    {
        return false;
    }

    /** The most rows a queued export writes. Default 1,000,000 (xlsx allows 1,048,575). */
    protected function queuedExportLimit(): int
    {
        return 1_000_000;
    }

    /** The filesystem disk queued exports are written to. Default `local`. */
    protected function exportDisk(): string
    {
        return 'local';
    }

    /**
     * The auth guard queued tasks run as and belong to. Default: the
     * request's guard (`auth:admin` routes use `admin`).
     */
    protected function taskGuard(): ?string
    {
        return null;
    }

    /** Queued tasks one visitor may start per 10 minutes. Default 10. */
    protected function queuedTaskLimit(): int
    {
        return 10;
    }

    /**
     * Start a queued task as the current user (or a guest's session), with
     * this table's state. One task at a time per table, within
     * `queuedTaskLimit()`; returns false (with an error) otherwise.
     */
    protected function queueTableTask(string $task, string $argument): bool
    {
        $current = $this->tableTask === null ? null : TableTasks::get($this->tableTask);
        if ($current !== null && in_array($current['status'] ?? null, ['queued', 'running'], true) && TableTasks::belongsTo($current)) {
            $this->addError('tableTask', $this->tableText('task_busy'));

            return false;
        }
        $guard = $this->taskGuard() ?? \Illuminate\Support\Facades\Auth::getDefaultDriver();
        $owner = TableTasks::owner($guard);
        if ($owner === null) {
            throw new \RuntimeException('Queued table tasks need a signed-in user or a session.');
        }
        $limiter = 'acun-datatable:tasks:'.hash('sha256', $owner);
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($limiter, $this->queuedTaskLimit())) {
            $this->addError('tableTask', $this->tableText('task_limit'));

            return false;
        }
        \Illuminate\Support\Facades\RateLimiter::hit($limiter, 600);
        $id = (string) \Illuminate\Support\Str::uuid();
        $user = \Illuminate\Support\Facades\Auth::guard($guard)->id();
        TableTasks::put($id, ['status' => 'queued', 'task' => $task, 'user' => $user, 'guard' => $guard, 'owner' => $owner]);
        // Through Livewire's synthesizers: models, enums, dates and forms travel as they do between requests.
        $synths = app(\Livewire\Mechanisms\HandleSynths\HandleSynths::class);
        $context = new \Livewire\Mechanisms\HandleComponents\ComponentContext($this);
        $state = [];
        foreach ($this->all() as $name => $value) {
            if ($name !== 'tableTask') {
                $state[$name] = $synths->dehydrate($value, $context, $name);
            }
        }
        $this->tableTask = $id;
        dispatch(new \Acunsoft\DataTable\Laravel\Jobs\RunTableTask(static::class, $state, $task, $argument, $id, $user, $guard, app()->getLocale()));

        return true;
    }

    /**
     * The queued job's work, on a component rebuilt from the table's state:
     * write the export to `exportDisk()`, or run the bulk action.
     *
     * @return array<string, mixed>
     */
    protected function runTableTask(string $task, string $argument, string $id): array
    {
        if ($task === 'bulk') {
            $found = $this->findAction($this->dataTableBulkActions(), $argument) ?? throw new \RuntimeException("Unknown bulk action [{$argument}].");
            $found->run($this->selectedQuery());

            return [];
        }
        $query = $this->dataTableQuery();
        $selected = $this->selectable() && $this->selectedCount() > 0;
        $export = TableExport::for($selected ? $this->selectedQuery() : $this->query())
            ->columns($this->dataTableVisibleColumns())
            ->key($this->keyName())
            ->limit(min($this->queuedExportLimit(), \Acunsoft\DataTable\Laravel\Export\XlsxWriter::MAX_ROWS))
            ->query($selected
                ? TableQuery::fromArray(['sort' => implode(',', array_map(fn (array $rule) => "{$rule['field']}:{$rule['direction']}", $query->sorting))])
                : $query, $this->dataTableColumns());
        $format = $argument === 'xlsx' ? 'xlsx' : 'csv';
        $temp = tempnam(sys_get_temp_dir(), 'adt-task-') ?: throw new \RuntimeException('Cannot create a temporary file.');
        try {
            $format === 'xlsx' ? $export->saveXlsx($temp, $this->exportFilename()) : $export->saveCsv($temp, $this->exportDelimiter());
            $path = "acun-datatable/{$id}.{$format}";
            \Illuminate\Support\Facades\Storage::disk($this->exportDisk())->putFileAs('acun-datatable', new \Illuminate\Http\File($temp), "{$id}.{$format}");
        } finally {
            @unlink($temp);
        }

        return ['disk' => $this->exportDisk(), 'path' => $path, 'name' => "{$this->exportFilename()}.{$format}"];
    }

    /** Polled while a task runs: shows its result once it is done. */
    public function checkTableTask(): void
    {
        $state = $this->tableTask === null ? null : TableTasks::get($this->tableTask);
        if ($state === null || ! TableTasks::belongsTo($state)) {
            $this->tableTask = null;

            return;
        }
        if (in_array($state['status'] ?? null, ['queued', 'running'], true)) {
            // Polled every 2 s: nothing to show yet, so the table (its counts and page) is not read again.
            $this->skipRender();

            return;
        }
        if (($state['status'] ?? null) === 'ready' && ($state['task'] ?? null) === 'bulk') {
            unset($this->rows); // show the changed rows
        }
    }

    /** Hide the result of the finished task. */
    public function dismissTableTask(): void
    {
        $this->tableTask = null;
    }

    /**
     * For the view: the task's status (`queued`, `running`, `ready`, `failed`), its
     * kind and, for a ready export, a signed download link valid for an hour.
     *
     * @return array{status: string, task: string, url: ?string, message: ?string}|null
     */
    protected function tableTaskView(): ?array
    {
        $state = $this->tableTask === null ? null : TableTasks::get($this->tableTask);
        if ($state === null) {
            return null;
        }
        $ready = ($state['status'] ?? null) === 'ready' && isset($state['path']);

        return [
            'status' => (string) ($state['status'] ?? 'queued'),
            'task' => (string) ($state['task'] ?? 'export'),
            'url' => $ready ? \Illuminate\Support\Facades\URL::temporarySignedRoute('acun-datatable.download', now()->addHour(), ['id' => $this->tableTask]) : null,
            'message' => isset($state['message']) ? (string) $state['message'] : null,
        ];
    }
}
