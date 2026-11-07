{{-- Usage: @include('partials.badges', ['status' => 'pending']) / ['pay' => 'Fully Paid'] / ['ver' => 'verified'] --}}
@isset($status)<span class="badge {{ \App\Models\RentalRequest::statusClass($status) }}">{{ \App\Models\RentalRequest::statusLabel($status) }}</span>@endisset
@isset($pay)<span class="badge {{ \App\Domain\Finance::badge($pay) }}">{{ $pay }}</span>@endisset
@isset($ver)<span class="badge {{ ['pending' => 'b-gold', 'verified' => 'b-green', 'rejected' => 'b-red'][$ver] ?? 'b-gray' }}">{{ ['pending' => 'Pending Verification', 'verified' => 'Verified', 'rejected' => 'Rejected'][$ver] ?? $ver }}</span>@endisset
