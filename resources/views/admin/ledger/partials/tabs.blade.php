{{-- Ledger tabs. The user and date filters carry over between them. --}}
@php
    $carry = array_filter([
        'user' => request()->query('user'),
        'from' => request()->query('from'),
        'to' => request()->query('to'),
    ], fn ($v) => is_string($v) && $v !== '');
@endphp
<div class="d-flex gap-2 mb-3">
    <a href="{{ route('admin.ledger.index', $carry) }}" class="{{ $active === 'transactions' ? 'btn-tv' : 'btn-tv-outline' }}">
        <i class='bx bx-book-content me-1'></i>Transactions
    </a>
    <a href="{{ route('admin.ledger.fees', $carry) }}" class="{{ $active === 'fees' ? 'btn-tv' : 'btn-tv-outline' }}">
        <i class='bx bx-receipt me-1'></i>Stripe fees
    </a>
</div>
