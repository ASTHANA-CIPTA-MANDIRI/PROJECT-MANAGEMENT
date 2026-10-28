{{-- Styles: "Paket (riwayat)" block in resources/css/filament.scss. --}}
<section class="rk-plans__history" aria-label="{{ __('Plan history') }}">
    <h3>{{ __('Plan history') }}</h3>

    @include('filament.pages.partials.billing-plan-history', ['rows' => $rows])
</section>
