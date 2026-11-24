{{--
    Refund popup. Filled from GET admin/payment-holds/{hold}/refund-preview.
    The admin may refund the whole deposit or a part of it, up to the maximum
    the server works out; the server checks the amount again before refunding.
--}}
<style>
    #refundModal [data-refund-submit]:disabled { opacity: 0.45; cursor: not-allowed; }
    #refundModal .refund-figure { font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; }
    #refundModal .refund-figure strong { display: block; font-size: 15px; color: #111827; text-transform: none; letter-spacing: 0; }
</style>
<div class="modal fade" id="refundModal" tabindex="-1" aria-labelledby="refundModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border:1px solid rgba(189,126,46,0.2); border-radius:12px; overflow:hidden;">
            <div class="modal-header" style="background:rgba(189,126,46,0.06); border-bottom:1px solid rgba(189,126,46,0.12); padding:16px 20px;">
                <h6 class="modal-title" id="refundModalTitle" style="font-weight:700; color:#111827;">
                    <i class='bx bx-undo me-2' style="color:#92621a;"></i>Refund deposit <span data-refund="hold_ref" style="color:#2563eb;"></span>
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body" style="padding:20px;">
                <div data-refund-loading class="text-center py-4" style="color:#6b7280;">
                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Checking this deposit…
                </div>

                <div data-refund-error class="alert-error-tv" style="display:none;"></div>

                <div data-refund-body style="display:none;">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div style="border:1px solid rgba(0,0,0,0.06); border-radius:10px; padding:14px 16px; height:100%;">
                                <div style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">User</div>
                                <div style="font-size:14px; font-weight:700; color:#111827;"><a data-refund="user_link" href="#" target="_blank" rel="noopener" style="color:#111827; text-decoration:none;"><span data-refund="user_name"></span></a></div>
                                <div style="font-size:13px; color:#4b5563;" data-refund="user_email"></div>
                                <div style="font-size:12px; color:#6b7280;">User ID: <span data-refund="user_id"></span></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div style="border:1px solid rgba(37,99,235,0.18); background:rgba(37,99,235,0.04); border-radius:10px; padding:14px 16px; height:100%;">
                                <div style="font-size:11px; color:#374151; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">Card</div>
                                <div style="font-size:16px; font-weight:700; color:#111827; font-family:monospace;" data-refund="card_label"></div>
                                <div style="font-size:12px; color:#4b5563; text-transform:capitalize;" data-refund="card_funding"></div>
                                <div style="font-size:12px; color:#1d4ed8; font-weight:600; margin-top:4px;"><i class='bx bxs-lock-alt me-1'></i>Money returns only to this card</div>
                            </div>
                        </div>
                    </div>

                    <div style="border:1px solid rgba(0,0,0,0.06); border-radius:10px; padding:12px 16px; margin-bottom:14px;">
                        <div class="row g-2" style="font-size:13px;">
                            <div class="col-6 col-md-3"><span style="color:#6b7280;">Title</span><br><strong data-refund="title"></strong></div>
                            <div class="col-6 col-md-3"><span style="color:#6b7280;">Deposited</span><br><strong data-refund="deposited_at"></strong></div>
                            <div class="col-6 col-md-3"><span style="color:#6b7280;">Unlock date</span><br><strong data-refund="unlock_at"></strong></div>
                            <div class="col-6 col-md-3"><span style="color:#6b7280;">Status</span><br><strong data-refund="status"></strong></div>
                            <div class="col-6 col-md-3"><span style="color:#6b7280;">Stripe payment</span><br><strong style="font-family:monospace;" data-refund="payment_ref"></strong></div>
                        </div>
                    </div>

                    <div class="row g-2 mb-3 text-center">
                        <div class="col-6 col-md-3"><div class="refund-figure">Deposit<strong data-refund="deposit"></strong></div></div>
                        <div class="col-6 col-md-3"><div class="refund-figure">Already refunded<strong data-refund="already_refunded"></strong></div></div>
                        <div class="col-6 col-md-3"><div class="refund-figure">In the vault<strong data-refund="in_vault"></strong></div></div>
                        <div class="col-6 col-md-3"><div class="refund-figure" style="color:#92621a;">Max refundable<strong data-refund="max" style="color:#92621a;"></strong></div></div>
                    </div>

                    <div data-refund-note class="alert-error-tv mb-3" style="display:none; background:rgba(255,152,0,0.08); border-color:rgba(255,152,0,0.3); color:#92400e;"></div>
                    <div data-refund-blocked class="alert-error-tv" style="display:none;"></div>

                    <form method="POST" action="#" data-refund-form style="display:none;" novalidate>
                        @csrf
                        <input type="hidden" name="request_token" data-refund-token>

                        <div style="background:#f2cf7a; border-left:4px solid #935510; border-radius:10px; padding:14px 18px; margin-bottom:14px;">
                            <label class="form-label" for="refund_amount" style="font-size:11px; color:#6b3a0a; font-weight:700; text-transform:uppercase; letter-spacing:0.6px;">Amount to refund (USD)</label>
                            <div class="d-flex gap-2 align-items-center">
                                <div class="input-group" style="max-width:260px;">
                                    <span class="input-group-text" style="font-weight:700;">$</span>
                                    <input type="text" inputmode="decimal" name="amount" id="refund_amount" class="form-control" style="font-size:20px; font-weight:800;" autocomplete="off" required>
                                </div>
                                <button type="button" class="btn-tv-outline" data-refund-max style="padding:6px 14px;">Full amount</button>
                            </div>
                            <div style="font-size:12px; color:#6b3a0a; margin-top:6px;" data-refund-split></div>
                            <div style="font-size:12px; color:#c82333; font-weight:600; margin-top:4px; display:none;" data-refund-amount-error></div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-5">
                                <label class="form-label" for="refund_reason_code" style="font-size:13px; font-weight:600;">Reason</label>
                                <select name="reason_code" id="refund_reason_code" class="form-select" required>
                                    @foreach(\App\Models\Refund::REASONS as $code => $label)
                                        <option value="{{ $code }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-7">
                                <label class="form-label" for="refund_reason_note" style="font-size:13px; font-weight:600;">Note (for the records)</label>
                                <input type="text" name="reason_note" id="refund_reason_note" class="form-control" minlength="5" maxlength="500" required placeholder="Why is this deposit being refunded?">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label" for="refund_password" style="font-size:13px; font-weight:600;">Your admin password</label>
                                <input type="password" name="password" id="refund_password" class="form-control" autocomplete="current-password" required>
                            </div>
                            <div class="col-md-7 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="confirm" value="1" id="refund_confirm" required>
                                    <label class="form-check-label" for="refund_confirm" style="font-size:13px; font-weight:600;" data-refund="confirm_text"></label>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="modal-footer" style="border-top:1px solid rgba(0,0,0,0.06);">
                <button type="button" class="btn-tv-outline" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn-tv" data-refund-submit disabled style="background:#c82333; border-color:#c82333;">
                    <span data-refund-submit-label>Refund</span>
                </button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const modalEl = document.getElementById('refundModal');
    if (!modalEl) { return; }

    const $ = (sel) => modalEl.querySelector(sel);
    const field = (name) => modalEl.querySelector('[data-refund="' + name + '"]');
    const form = $('[data-refund-form]');
    const amountInput = $('#refund_amount');
    const confirmBox = $('#refund_confirm');
    const submit = $('[data-refund-submit]');
    const submitLabel = $('[data-refund-submit-label]');
    const amountError = $('[data-refund-amount-error]');
    const money = (cents) => '$' + new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(cents / 100);
    const toCents = (n) => Math.round(Number(n) * 100);
    let current = null;

    function show(el, visible) { el.style.display = visible ? '' : 'none'; }

    function newToken() {
        if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    function reset() {
        current = null;
        show($('[data-refund-loading]'), true);
        show($('[data-refund-error]'), false);
        show($('[data-refund-body]'), false);
        show($('[data-refund-blocked]'), false);
        show($('[data-refund-note]'), false);
        show(form, false);
        form.reset();
        submit.disabled = true;
        submitLabel.textContent = 'Refund';
    }

    /** The amount typed, in cents, or null when it is not a valid amount. */
    function enteredCents() {
        const raw = amountInput.value.trim().replace(/,/g, '');
        if (!/^\d{1,7}(\.\d{1,2})?$/.test(raw)) { return null; }
        return toCents(raw);
    }

    function update() {
        if (!current) { return; }
        const cents = enteredCents();
        const max = toCents(current.money.max_refundable);
        const vault = toCents(current.money.in_vault);
        let error = '';

        if (cents === null) {
            error = 'Enter an amount like 25 or 25.50.';
        } else if (cents < 1) {
            error = 'Enter an amount of at least $0.01.';
        } else if (cents > max) {
            error = 'The most you can refund now is ' + money(max) + '.';
        }

        amountError.textContent = error;
        show(amountError, error !== '');

        const valid = error === '';
        const label = valid ? money(cents) : '$—';
        const fromVault = valid ? Math.min(vault, cents) : 0;
        const fromConnect = valid ? cents - fromVault : 0;

        $('[data-refund-split]').textContent = !valid ? '' : (fromConnect > 0
            ? money(fromVault) + ' from the vault + ' + money(fromConnect) + ' pulled back from the user\'s Connect account first'
            : 'All of it comes from the money still in the vault')
            + (valid && cents < max ? ' · partial refund' : (valid ? ' · full remaining amount' : ''));

        field('confirm_text').textContent = 'I confirm ' + label + ' goes back to ' + current.card_label;
        submitLabel.textContent = valid ? 'Refund ' + label : 'Refund';
        submit.disabled = !valid;
    }

    function render(data) {
        current = data;
        field('hold_ref').textContent = '#' + data.deposit.hold_ref;
        field('user_name').textContent = data.user.name;
        field('user_email').textContent = data.user.email;
        field('user_id').textContent = data.user.id ?? '—';
        field('user_link').href = data.user.url || '#';
        field('card_label').textContent = data.card.label;
        field('card_funding').textContent = data.card.funding ? data.card.funding + ' card' : '';
        field('title').textContent = data.deposit.title || '—';
        field('deposited_at').textContent = data.deposit.deposited_at || '—';
        field('unlock_at').textContent = data.deposit.unlock_at || '—';
        field('status').textContent = (data.deposit.status || '').replace(/_/g, ' ');
        field('payment_ref').textContent = data.deposit.payment_ref;
        field('deposit').textContent = money(toCents(data.money.deposit));
        field('already_refunded').textContent = money(toCents(data.money.already_refunded));
        field('in_vault').textContent = money(toCents(data.money.in_vault));
        field('max').textContent = money(toCents(data.money.max_refundable));

        show($('[data-refund-loading]'), false);
        show($('[data-refund-body]'), true);

        if (data.note) {
            const note = $('[data-refund-note]');
            note.textContent = data.note;
            show(note, true);
        }

        if (!data.eligible) {
            const blocked = $('[data-refund-blocked]');
            blocked.textContent = data.reason;
            show(blocked, true);
            return;
        }

        form.action = data.refund_url;
        $('[data-refund-token]').value = newToken();
        amountInput.value = Number(data.money.max_refundable).toFixed(2);
        show(form, true);
        update();
    }

    amountInput.addEventListener('input', update);
    $('[data-refund-max]').addEventListener('click', function () {
        if (!current) { return; }
        amountInput.value = Number(current.money.max_refundable).toFixed(2);
        update();
    });

    modalEl.addEventListener('show.bs.modal', function (event) {
        const url = event.relatedTarget && event.relatedTarget.getAttribute('data-preview-url');
        reset();
        if (!url) { return; }

        fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then(r => { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(render)
            .catch(() => {
                show($('[data-refund-loading]'), false);
                const err = $('[data-refund-error]');
                err.textContent = 'Could not load this deposit. Reload the page and try again.';
                show(err, true);
            });
    });

    submit.addEventListener('click', function () {
        if (submit.disabled || enteredCents() === null) { return; }
        if (!form.reportValidity()) { return; }
        if (!confirmBox.checked) { confirmBox.reportValidity(); return; }

        amountInput.value = (enteredCents() / 100).toFixed(2);
        submit.disabled = true;
        submitLabel.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Refunding…';
        form.submit();
    });
})();
</script>
@endpush
