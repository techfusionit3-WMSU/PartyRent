{{-- Usage: @include('partials.badges', ['status' => 'pending']) / ['pay' => 'Fully Paid'] / ['ver' => 'verified'] --}}
@isset($status)<span class="badge {{ \App\Models\RentalRequest::statusClass($status) }}">{{ \App\Models\RentalRequest::statusLabel($status) }}</span>@endisset
@isset($pay)<span class="badge {{ \App\Domain\Finance::badge($pay) }}">{{ $pay }}</span>@endisset
@isset($ver)<span class="badge {{ ['awaiting' => 'b-sky', 'pending' => 'b-gold', 'verified' => 'b-green', 'rejected' => 'b-red'][$ver] ?? 'b-gray' }}">{{ \App\Models\Payment::STATUS_LABEL[$ver] ?? $ver }}</span>@endisset
@isset($planBadge)<span class="badge {{ ['standard' => 'b-sky', 'full' => 'b-green', 'installment' => 'b-purple'][$planBadge] ?? 'b-gray' }} plain">{{ \App\Domain\Finance::PLAN_LABEL[$planBadge] ?? $planBadge }}</span>@endisset
