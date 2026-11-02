<?php

declare(strict_types=1);

namespace Acunsoft\DataTable\Laravel\Livewire\Concerns;

/**
 * Child rows (`detailView()`) opened and closed per row.
 *
 * @internal part of {@see \Acunsoft\DataTable\Laravel\Livewire\WithDataTable}; use that trait.
 */
trait ShowsChildRows
{
    /** Open or close a row's child row (`detailView()`); `null` toggles. Only rows of the current page open. */
    public function toggleDetail(string|int $key, ?bool $open = null): void
    {
        $key = (string) $key;
        $keys = $this->openDetailKeys();
        $isOpen = in_array($key, $keys, true);
        if ($open ?? ! $isOpen) {
            if (! $isOpen && $this->detailView() !== null && in_array($key, $this->pageKeys(), true)) {
                $keys = array_slice([...$keys, $key], -100);
            }
        } else {
            $keys = array_values(array_diff($keys, [$key]));
        }
        $this->openDetails = $keys;
    }

    protected function detailIsOpen(string $key): bool
    {
        return in_array($key, $this->openDetailKeys(), true);
    }

    /** @return list<string> */
    private function openDetailKeys(): array
    {
        return self::distinctKeys($this->openDetails, 100);
    }
}
