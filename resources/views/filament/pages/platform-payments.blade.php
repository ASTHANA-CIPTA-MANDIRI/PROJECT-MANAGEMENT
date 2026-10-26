<x-filament::page>
    @php($stats = $this->paymentStats())

    {{-- Page-scoped styles (every selector starts with .rk-pp); the shared stylesheet is not touched.
         The table card / toolbar / header rules mirror the ones the Organizations table gets from
         filament.scss (that list is keyed by page, and this page is not in it). The native Filament
         pager is deliberately left visible: the mirrored `.rk-pager` is not wired up for this page. --}}
    <style>
        .rk-pp__muted { color: #9ca3af; }

        /* Summary cards: 1 col, 2 cols, 3+2 (6-col grid, no orphan), then 5 across. */
        .rk-pp .rk-pl__stats { grid-template-columns: minmax(0, 1fr); }
        .rk-pp .rk-pl__stat { min-width: 0; }
        .rk-pp .rk-pl__stat em { line-height: 1.35; }
        @media (min-width: 640px) {
            .rk-pp .rk-pl__stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .rk-pp .rk-pl__stat:last-child:nth-child(odd) { grid-column: 1 / -1; }
        }
        @media (min-width: 1024px) {
            .rk-pp .rk-pl__stats { grid-template-columns: repeat(6, minmax(0, 1fr)); }
            .rk-pp .rk-pl__stat { grid-column: span 2; }
            .rk-pp .rk-pl__stat:nth-child(n+4) { grid-column: span 3; }
            .rk-pp .rk-pl__stat:last-child:nth-child(odd) { grid-column: span 3; }
        }
        @media (min-width: 1536px) {
            .rk-pp .rk-pl__stats { grid-template-columns: repeat(5, minmax(0, 1fr)); }
            .rk-pp .rk-pl__stat,
            .rk-pp .rk-pl__stat:nth-child(n+4),
            .rk-pp .rk-pl__stat:last-child:nth-child(odd) { grid-column: span 1; }
        }

        /* Table card: one big rounded white card holding header, toolbar and table. */
        .rk-pp .filament-tables-container { overflow: hidden; border: 0; border-radius: 1.5rem; background: #fff; box-shadow: 0 1px 2px rgba(15, 23, 42, .05); }
        .rk-pp .filament-tables-header-container { padding: 1.25rem 1.25rem .75rem; }
        .rk-pp .filament-tables-header-heading { font-size: 1.125rem; font-weight: 600; color: #111827; }
        .rk-pp .filament-tables-header-description { margin-top: .125rem; font-size: .875rem; font-weight: 400; color: #6b7280; }
        .rk-pp .filament-tables-header-toolbar { height: auto; gap: .75rem; padding: 0; }
        .rk-pp .filament-tables-search-container { margin: 0; flex: 1 1 0%; justify-content: flex-start; }
        .rk-pp .filament-tables-search-container label { width: 100%; max-width: 20rem; }
        .rk-pp .filament-tables-search-container input { height: 2.5rem; border-radius: 9999px; border-color: #e5e7eb; background: #fff; padding-left: 2.5rem; font-size: .875rem; box-shadow: none; }

        /* Rounded bordered table, light headers, roomy rows; scrolls sideways inside its card. */
        .rk-pp .filament-tables-table-container { margin: 0 1.25rem .5rem; overflow-x: auto; border: 1px solid #e5e7eb; border-radius: 1rem; -webkit-overflow-scrolling: touch; }
        .rk-pp .filament-tables-table thead tr { background: #fff; }
        .rk-pp .filament-tables-table th { border-bottom: 1px solid #e5e7eb; background: #fff; padding: .75rem .5rem; font-size: .75rem; font-weight: 500; text-transform: none; letter-spacing: normal; color: #6b7280; white-space: nowrap; }
        .rk-pp .filament-tables-table th button,
        .rk-pp .filament-tables-table th span { font-size: .75rem; font-weight: 500; text-transform: none; letter-spacing: normal; color: #6b7280; }
        .rk-pp .filament-tables-table tbody tr { border-bottom: 1px solid #f3f4f6; }
        .rk-pp .filament-tables-table tbody tr:last-child { border-bottom: 0; }
        .rk-pp .filament-tables-table tbody tr:hover { background: #f8fafc; }
        .rk-pp .filament-tables-table td { padding: 1rem .5rem; font-size: .875rem; color: #4b5563; }
        .rk-pp .filament-tables-table td .filament-tables-column-wrapper,
        .rk-pp .filament-tables-table td .filament-tables-text-column { padding-left: .25rem; padding-right: .25rem; }

        /* Columns keep their natural, readable width; a narrower screen scrolls the card sideways
           while the actions column (last) stays pinned to the right edge. */
        .rk-pp .filament-table-cell-invoice-number { min-width: 10rem; white-space: nowrap; font-weight: 600; color: #111827; }
        .rk-pp .filament-table-cell-invoice-number * { font-weight: 600; color: #111827; }
        .rk-pp .filament-table-cell-organization-name { min-width: 8rem; }
        .rk-pp .filament-table-cell-plan-code { min-width: 5rem; white-space: nowrap; }
        .rk-pp .filament-table-cell-amount { min-width: 7rem; white-space: nowrap; }
        .rk-pp .filament-table-cell-status,
        .rk-pp .filament-table-cell-attention { min-width: 6rem; white-space: nowrap; }
        .rk-pp .filament-table-cell-created-at,
        .rk-pp .filament-table-cell-expires-at,
        .rk-pp .filament-table-cell-paid-at { min-width: 6rem; white-space: nowrap; }

        /* Actions column: last, pinned right (white like the row, thin shadow on its left), header and icons centred. */
        .rk-pp .filament-tables-table th:last-child,
        .rk-pp .filament-tables-table td:last-child { position: sticky; right: 0; z-index: 1; background: #fff; box-shadow: -8px 0 8px -8px rgba(15, 23, 42, .15); text-align: center; }
        .rk-pp .filament-tables-table tbody tr:hover td:last-child { background: #f8fafc; }
        .rk-pp .filament-tables-table th:last-child > *,
        .rk-pp .filament-tables-table th:last-child span { justify-content: center; text-align: center; }
        .rk-pp .filament-tables-actions-container { flex-wrap: nowrap; justify-content: center; gap: .625rem; min-width: 8.5rem; }

        /* Three outline icons, no button chrome (soft colours of the page's chips). 2.25rem hit area, 1.375rem icon. */
        .rk-pp .filament-tables-actions-container .rk-pp__act { margin: 0; height: 2.25rem; width: 2.25rem; background: transparent; box-shadow: none; transition: background-color .15s, color .15s; }
        .rk-pp .filament-tables-actions-container .rk-pp__act svg { height: 1.375rem; width: 1.375rem; }
        .rk-pp .filament-tables-actions-container .rk-pp__act--check { color: #3b82d6; }
        .rk-pp .filament-tables-actions-container .rk-pp__act--sync { color: #4fb87a; }
        .rk-pp .filament-tables-actions-container .rk-pp__act--history { color: #ee8f6a; }
        .rk-pp .filament-tables-actions-container .rk-pp__act--check:hover,
        .rk-pp .filament-tables-actions-container .rk-pp__act--check:focus-visible { background: rgba(59, 130, 214, .1); }
        .rk-pp .filament-tables-actions-container .rk-pp__act--sync:hover,
        .rk-pp .filament-tables-actions-container .rk-pp__act--sync:focus-visible { background: rgba(79, 184, 122, .12); }
        .rk-pp .filament-tables-actions-container .rk-pp__act--history:hover,
        .rk-pp .filament-tables-actions-container .rk-pp__act--history:focus-visible { background: rgba(238, 143, 106, .12); }

        /* Legend under the table: same icons, same colours, so nobody has to guess what a glyph does. */
        .rk-pp__legend { display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; padding: .25rem 1.5rem 1.25rem; font-size: .8125rem; color: #6b7280; }
        .rk-pp__legend span { display: inline-flex; align-items: center; gap: .375rem; }
        .rk-pp__legend svg { height: 1.125rem; width: 1.125rem; }

        /* Empty state. */
        .rk-pp .filament-tables-empty-state { padding: 2.5rem 1rem; }
        .rk-pp .filament-tables-empty-state-heading { font-size: 1rem; font-weight: 600; color: #111827; }
        .rk-pp .filament-tables-empty-state-description { max-width: 28rem; font-size: .875rem; color: #6b7280; }

        .rk-pp .filament-tables-pagination-container { padding: .75rem 1.25rem; }

        @media (max-width: 639px) {
            .rk-pp .filament-tables-header-container { padding: .75rem .75rem .5rem; }
            .rk-pp .filament-tables-header-toolbar { flex-wrap: nowrap; gap: .5rem; }
            .rk-pp .filament-tables-search-container { min-width: 0; }
            .rk-pp .filament-tables-search-container label { max-width: none; }
            .rk-pp .filament-tables-table-container { margin-left: .75rem; margin-right: .75rem; }
            .rk-pp .filament-tables-table td { padding-top: .5rem; padding-bottom: .5rem; font-size: .75rem; }
        }
    </style>

    <div class="rk-pl rk-pp">
        <div class="rk-pl__stats">
            @foreach ($stats as $stat)
                <div class="rk-pl__stat">
                    <span class="rk-pl__stat-icon rk-pf__tile--{{ $stat['tone'] }}"><x-dynamic-component :component="'heroicon-o-'.$stat['icon']" class="h-6 w-6" /></span>
                    <div>
                        <small>{{ $stat['label'] }}</small>
                        <b>{{ $stat['value'] }}</b>
                        <em class="is-flat">{{ $stat['note'] }}</em>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="rk-pl__table">
            {{ $this->table }}

            <div class="rk-pp__legend" aria-label="{{ __('Actions') }}">
                <span style="color:#3b82d6"><x-heroicon-o-eye aria-hidden="true" /> <em style="font-style:normal;color:#6b7280">{{ __('Check status') }}</em></span>
                <span style="color:#4fb87a"><x-heroicon-o-refresh aria-hidden="true" /> <em style="font-style:normal;color:#6b7280">{{ __('Sync') }} ({{ __('pending only') }})</em></span>
                <span style="color:#ee8f6a"><x-heroicon-o-clock aria-hidden="true" /> <em style="font-style:normal;color:#6b7280">{{ __('History') }}</em></span>
            </div>
        </div>
    </div>
</x-filament::page>
