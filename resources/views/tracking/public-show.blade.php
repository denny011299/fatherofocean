<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Tracking {{ $barang->no_index }} — {{ config('app.name') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <x-vite-assets :entries="['resources/css/app.css']" />
</head>
<body class="public-track-detail" data-theme="light">
    <script>
        (function(){ var t = localStorage.getItem('theme') || 'light'; document.body.setAttribute('data-theme', t); })();
    </script>

    @php
        $currentStatus = $latestUpdate?->status ?? $barang->status;
        $isDelivered = $barang->status === \App\Models\Barang::STATUS_BONGKAR;
        $existingRating = $barang->rating;
    @endphp

    <header class="public-track-topbar">
        <a href="{{ route('track.index') }}" class="public-track-brand">
            @if(\App\Support\Branding::hasLogo())
                <span class="sidebar-brand-logo-wrap sidebar-brand-logo-wrap--compact">
                    <x-brand-mark variant="public-topbar-full" />
                </span>
            @else
                <x-brand-mark variant="public-topbar" class="me-1" /> PELAYARAN CONTROL
            @endif
        </a>
        <div class="d-flex align-items-center gap-2">
            <button class="theme-toggle" id="themeToggle" title="Toggle Light/Dark">
                <i class="bi bi-sun-fill"></i>
                <i class="bi bi-moon-fill"></i>
            </button>
        </div>
    </header>

    <div class="public-track-layout">
        <aside class="public-track-sidebar">
            <div class="glass public-track-sidebar-card">
                <div class="public-track-sidebar-avatar">
                    <i class="bi bi-person-fill"></i>
                </div>

                <div class="public-track-info-label">Nama Customer</div>
                <div class="public-track-info-value">{{ $markingRecord->customer?->name ?? $markingRecord->contact_person ?? '-' }}</div>

                <div class="public-track-info-label">Kode Marking</div>
                <div class="public-track-info-value public-track-info-accent">{{ $markingRecord->displayCode() }}</div>

                <div class="public-track-info-label">{{ __('tracking.col_no_index') }}</div>
                <div class="public-track-info-value">{{ $barang->no_index }}</div>

                @if($markingRecord->phone_hp)
                <div class="public-track-info-label">Kontak</div>
                <div class="public-track-info-value"><i class="bi bi-telephone me-1"></i>{{ $markingRecord->phone_hp }}</div>
                @endif

                @if($markingRecord->address_1)
                <div class="public-track-info-label">Alamat</div>
                <div class="public-track-info-value public-track-info-address">
                    {{ $markingRecord->address_1 }}
                    @if($markingRecord->address_2)<br>{{ $markingRecord->address_2 }}@endif
                </div>
                @endif

                @if($markingRecord->branch)
                <div class="public-track-info-label">Cabang</div>
                <div class="public-track-info-value">{{ $markingRecord->branch->name }}</div>
                @endif
            </div>
        </aside>

        <main class="public-track-main">
            <div class="public-track-tabs" role="tablist">
                <button type="button" class="public-track-tab active" data-tab="tracking" role="tab" aria-selected="true">
                    <i class="bi bi-geo-alt me-1"></i>Tracking
                </button>
                <button type="button" class="public-track-tab" data-tab="invoice" role="tab" aria-selected="false">
                    <i class="bi bi-receipt me-1"></i>Invoice
                </button>
            </div>

            <div class="public-track-panel active" id="panel-tracking" role="tabpanel">
                <div class="glass public-track-hero {{ $isDelivered ? 'is-delivered' : 'is-in-transit' }}">
                    <div class="public-track-hero-layout">
                        <div class="public-track-hero-primary">
                            <div class="public-track-hero-label">Nomor tracking</div>
                            <div class="public-track-hero-tracking">{{ $barang->no_index }}</div>
                            @if(!empty($tanggalMasukLabel))
                            <div class="public-track-hero-meta">
                                <i class="bi bi-calendar-event"></i>
                                <span>{{ $tanggalMasukFieldLabel }}: {{ $tanggalMasukLabel }}</span>
                            </div>
                            @endif
                        </div>
                        <div class="public-track-hero-highlight">
                            <div class="public-track-hero-status-icon" aria-hidden="true">
                                <i class="bi {{ $isDelivered ? 'bi-check2-circle' : 'bi-geo-alt-fill' }}"></i>
                            </div>
                            <div class="public-track-hero-status-copy">
                                <div class="public-track-hero-label">Status pengiriman</div>
                                <div class="public-track-hero-status">
                                    {{ $statusLabels[$currentStatus] ?? ucfirst(str_replace('_', ' ', $currentStatus)) }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="glass public-track-summary">
                    <div class="public-track-summary-grid">
                        <div class="public-track-summary-item">
                            <div class="public-track-summary-label">{{ $tanggalMasukFieldLabel }}</div>
                            <div class="public-track-summary-value">{{ $tanggalMasukLabel ?? '-' }}</div>
                        </div>
                        <div class="public-track-summary-item">
                            <div class="public-track-summary-label">CTNS</div>
                            <div class="public-track-summary-value">{{ $barang->ctns ?? '-' }}</div>
                        </div>
                        <div class="public-track-summary-item">
                            <div class="public-track-summary-label">CBM</div>
                            <div class="public-track-summary-value">{{ $barang->total_cbm ? \App\Support\CbmFormat::format((float) $barang->total_cbm) : '-' }}</div>
                        </div>
                        <div class="public-track-summary-item">
                            <div class="public-track-summary-label">Berat (kg)</div>
                            <div class="public-track-summary-value">{{ $barang->total_gross_weight ? number_format((float) $barang->total_gross_weight, 2) : '-' }}</div>
                        </div>
                        <div class="public-track-summary-item">
                            <div class="public-track-summary-label">Container</div>
                            <div class="public-track-summary-value">{{ $barang->containers->pluck('partai')->implode(', ') ?: '-' }}</div>
                        </div>
                        <div class="public-track-summary-item" style="min-width: 0;">
                            <div class="public-track-summary-label">{{ __('tracking.col_no_resi_pengiriman') }}</div>
                            <div class="public-track-summary-value public-track-summary-value--resi" style="font-size: .8rem; word-break: break-all; overflow-wrap: anywhere;">@include('_partials.no-resi-stack', ['value' => $barang->no_resi ?? null])</div>
                        </div>
                        <div class="public-track-summary-item">
                            <div class="public-track-summary-label">Status</div>
                            <div class="public-track-summary-value">
                                <span class="badge-glass badge-status-{{ $barang->status }}">
                                    {{ $statusLabels[$barang->status] ?? ucfirst(str_replace('_', ' ', $barang->status)) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    @if($barang->keterangan)
                    <p class="public-track-note">
                        <i class="bi bi-info-circle me-1"></i>{{ $barang->keterangan }}
                    </p>
                    @endif
                </div>

                <div class="glass public-track-timeline-card">
                    <h2 class="public-track-section-title">
                        <i class="bi bi-clock-history me-2"></i>Riwayat Tracking
                    </h2>

                    @if($customerTimeline->count() > 0)
                    <div class="public-track-timeline">
                        @foreach($customerTimeline as $tIdx => $tracking)
                        <div class="public-track-timeline-row {{ $tIdx === 0 ? 'is-current' : 'is-complete' }}">
                            <div class="public-track-timeline-axis">
                                <span class="public-track-timeline-dot"></span>
                            </div>
                            <div class="public-track-timeline-content">
                                <div class="public-track-timeline-title">
                                    {{ $statusLabels[$tracking->status] ?? ucfirst(str_replace('_', ' ', $tracking->status)) }}
                                </div>
                                <div class="public-track-timeline-meta">
                                    @if($tracking->location)
                                        <span><i class="bi bi-pin-map me-1"></i>{{ $tracking->location }}</span>
                                    @endif
                                </div>
                                @if($tracking->description)
                                <div class="public-track-timeline-desc">{{ $tracking->description }}</div>
                                @endif
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <div class="public-track-empty">
                        <i class="bi bi-clock"></i>
                        <div>{{ __('tracking.customer_empty_timeline') }}</div>
                    </div>
                    @endif
                </div>

                @if($isDelivered)
                <div class="glass public-track-feedback-card" id="publicTrackFeedback">
                    <h2 class="public-track-section-title">
                        <i class="bi bi-chat-heart me-2"></i>Pengalaman Pengiriman
                    </h2>

                    @if($existingRating)
                    <div class="public-track-feedback-submitted">
                        <div class="public-track-experience-row is-readonly" aria-label="Rating terkirim">
                            @for($score = 1; $score <= 5; $score++)
                            <span class="public-track-experience-option {{ $score <= $existingRating->score ? 'is-active' : '' }}" data-score="{{ $score }}">
                                <i class="bi bi-emoji-smile"></i>
                            </span>
                            @endfor
                        </div>
                        <p class="public-track-feedback-thanks">
                            <i class="bi bi-check-circle me-1"></i>Terima kasih, ulasan Anda sudah kami terima.
                        </p>
                        @if($existingRating->review)
                        <blockquote class="public-track-feedback-review">{{ $existingRating->review }}</blockquote>
                        @endif
                        @if($invoices->isNotEmpty())
                        @php
                            $ratingInvoice = $invoices->first();
                            $ratingInvoicePdfUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                                'track.invoice.pdf',
                                now()->addDays(30),
                                ['invoice' => $ratingInvoice->id, 'no_index' => $barang->no_index]
                            );
                        @endphp
                        <div class="public-track-feedback-actions mt-3">
                            <a href="{{ $ratingInvoicePdfUrl }}" target="_blank" rel="noopener" class="btn btn-glass btn-sm">
                                <i class="bi bi-download me-1"></i>{{ __('dashboard.action_download_invoice') }}
                            </a>
                        </div>
                        @endif
                    </div>
                    @else
                    <p class="public-track-feedback-lead">Bagaimana pengalaman pengiriman Anda?</p>
                    <div class="public-track-experience-row" id="publicTrackExperience" role="radiogroup" aria-label="Rating pengiriman">
                        @foreach([
                            1 => ['label' => 'Sangat buruk', 'icon' => 'bi-emoji-angry'],
                            2 => ['label' => 'Buruk', 'icon' => 'bi-emoji-frown'],
                            3 => ['label' => 'Cukup', 'icon' => 'bi-emoji-neutral'],
                            4 => ['label' => 'Baik', 'icon' => 'bi-emoji-smile'],
                            5 => ['label' => 'Sangat baik', 'icon' => 'bi-emoji-heart-eyes'],
                        ] as $score => $option)
                        <button type="button" class="public-track-experience-option" data-score="{{ $score }}" aria-label="{{ $option['label'] }}">
                            <i class="bi {{ $option['icon'] }}"></i>
                            <span>{{ $score }}</span>
                        </button>
                        @endforeach
                    </div>
                    <form id="publicTrackRatingForm" class="public-track-feedback-form">
                        <input type="hidden" name="score" id="publicTrackRatingScore" value="">
                        <label for="publicTrackReview" class="form-label">Ulasan / kritik / saran</label>
                        <textarea
                            id="publicTrackReview"
                            name="review"
                            class="form-control"
                            rows="4"
                            maxlength="1000"
                            placeholder="Ceritakan pengalaman Anda agar kami bisa lebih baik..."
                        ></textarea>
                        <div class="public-track-feedback-actions">
                            <button type="submit" class="btn btn-accent" id="publicTrackRatingSubmit" disabled>
                                <i class="bi bi-send me-1"></i>Kirim Ulasan
                            </button>
                            @if($invoices->isNotEmpty())
                            @php
                                $ratingInvoice = $invoices->first();
                                $ratingInvoicePdfUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute(
                                    'track.invoice.pdf',
                                    now()->addDays(30),
                                    ['invoice' => $ratingInvoice->id, 'no_index' => $barang->no_index]
                                );
                            @endphp
                            <a href="{{ $ratingInvoicePdfUrl }}" target="_blank" rel="noopener" class="btn btn-glass">
                                <i class="bi bi-download me-1"></i>{{ __('dashboard.action_download_invoice') }}
                            </a>
                            @endif
                        </div>
                        <div class="public-track-feedback-message" id="publicTrackRatingMessage" hidden></div>
                    </form>
                    @endif
                </div>
                @endif
            </div>

            <div class="public-track-panel" id="panel-invoice" role="tabpanel">
                @if($invoices->isNotEmpty())
                    @foreach($invoices as $invoice)
                    <div class="glass public-track-invoice-card">
                        <div class="public-track-invoice-header">
                            <div>
                                <div class="public-track-invoice-no">{{ $invoice->displayInvoiceNo() }}</div>
                                @php
                                    $invPaid = $invoice->payments->where('status', 'approved')->sum('amount');
                                    $invBalance = max(0, (float) $invoice->grand_total - $invPaid);
                                    $invStatus = \App\Support\InvoiceStatusPresenter::forInvoice($invoice, $invPaid, $invBalance);
                                    $invDueClass = \App\Support\InvoiceStatusPresenter::dueDateCellClass($invoice->due_date);
                                    $invShipmentGroups = $invoice->items->groupBy(
                                        static fn ($item) => trim((string) ($item->barang?->no_index ?? '')) ?: '-',
                                    );
                                @endphp
                                <div class="public-track-invoice-date">
                                    {{ $invoice->created_at->format('d M Y') }}
                                    @if($invoice->due_date)
                                        — {{ __('finance.col_due_date') }}:
                                        <span class="{{ $invDueClass }}">{{ \App\Support\InvoiceStatusPresenter::formatDueDate($invoice->due_date) }}</span>
                                    @endif
                                </div>
                            </div>
                            <span class="{{ $invStatus['class'] }}">{{ $invStatus['label'] }}</span>
                        </div>

                        <div class="public-track-invoice-rows">
                            <div class="public-track-invoice-row">
                                <span>Total CBM</span>
                                <span>{{ \App\Support\CbmFormat::format((float) $invoice->total_cbm) }}</span>
                            </div>
                            <div class="public-track-invoice-row">
                                <span>Subtotal</span>
                                <span>Rp {{ number_format((float) $invoice->subtotal, 0, ',', '.') }}</span>
                            </div>
                            @if((float) $invoice->insurance_total > 0)
                            <div class="public-track-invoice-row">
                                <span>Asuransi</span>
                                <span>Rp {{ number_format((float) $invoice->insurance_total, 0, ',', '.') }}</span>
                            </div>
                            @endif
                            @if((float) $invoice->extra_charges > 0)
                            <div class="public-track-invoice-row">
                                <span>Biaya Tambahan</span>
                                <span>Rp {{ number_format((float) $invoice->extra_charges, 0, ',', '.') }}</span>
                            </div>
                            @endif
                            <div class="public-track-invoice-row public-track-invoice-total">
                                <span>Grand Total</span>
                                <span>Rp {{ number_format((float) $invoice->grand_total, 0, ',', '.') }}</span>
                            </div>
                        </div>

                        <div class="public-track-invoice-actions">
                            <button type="button" class="btn btn-glass btn-sm" onclick="toggleInvoiceItems({{ $invoice->id }})">
                                <i class="bi bi-list-ul me-1"></i>Detail Item
                            </button>
                            <a
                                href="{{ URL::temporarySignedRoute('track.invoice.pdf', now()->addDays(30), ['invoice' => $invoice->id, 'no_index' => $barang->no_index]) }}"
                                target="_blank"
                                class="btn btn-accent btn-sm"
                            >
                                <i class="bi bi-download me-1"></i>Download PDF
                            </a>
                        </div>

                        <div class="public-track-invoice-items" id="inv-items-{{ $invoice->id }}">
                            <table>
                                <thead>
                                    <tr>
                                        <th>{{ __('tracking.col_no_index') }}</th>
                                        <th>CBM</th>
                                        <th>Harga/CBM</th>
                                        <th>Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($invShipmentGroups as $noIndex => $groupItems)
                                    @if($invShipmentGroups->count() > 1)
                                    <tr>
                                        <td colspan="4" class="fw-600" style="background:rgba(255,255,255,.04)">{{ __('tracking.col_no_index') }}: {{ $noIndex }}</td>
                                    </tr>
                                    @endif
                                    @foreach($groupItems as $item)
                                    <tr>
                                        <td>{{ $item->barang?->no_index ?? '-' }}</td>
                                        <td>{{ \App\Support\CbmFormat::format((float) $item->cbm) }}</td>
                                        <td>Rp {{ number_format((float) $item->price_per_cbm, 0, ',', '.') }}</td>
                                        <td>Rp {{ number_format((float) $item->line_total, 0, ',', '.') }}</td>
                                    </tr>
                                    @endforeach
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                    @endforeach
                @else
                <div class="glass public-track-empty public-track-empty-card">
                    <i class="bi bi-receipt"></i>
                    <div>Belum ada invoice</div>
                    <p>Tagihan untuk pengiriman ini belum tersedia. Silakan hubungi staff kami jika Anda membutuhkan informasi billing.</p>
                </div>
                @endif
            </div>
        </main>
    </div>

    <script>
        document.getElementById('themeToggle').addEventListener('click', function() {
            var current = document.body.getAttribute('data-theme') || 'dark';
            var next = current === 'dark' ? 'light' : 'dark';
            document.body.setAttribute('data-theme', next);
            localStorage.setItem('theme', next);
        });

        document.querySelectorAll('.public-track-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                var target = tab.getAttribute('data-tab');
                document.querySelectorAll('.public-track-tab').forEach(function(item) {
                    item.classList.toggle('active', item === tab);
                    item.setAttribute('aria-selected', item === tab ? 'true' : 'false');
                });
                document.querySelectorAll('.public-track-panel').forEach(function(panel) {
                    panel.classList.toggle('active', panel.id === 'panel-' + target);
                });
            });
        });

        function toggleInvoiceItems(id) {
            var wrap = document.getElementById('inv-items-' + id);
            wrap.classList.toggle('is-open');
        }

        (function() {
            var experience = document.getElementById('publicTrackExperience');
            var form = document.getElementById('publicTrackRatingForm');
            if (!experience || !form) {
                return;
            }

            var scoreInput = document.getElementById('publicTrackRatingScore');
            var submitButton = document.getElementById('publicTrackRatingSubmit');
            var message = document.getElementById('publicTrackRatingMessage');
            var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            experience.querySelectorAll('.public-track-experience-option').forEach(function(option) {
                option.addEventListener('click', function() {
                    var score = option.getAttribute('data-score');
                    scoreInput.value = score;
                    experience.querySelectorAll('.public-track-experience-option').forEach(function(item) {
                        item.classList.toggle('is-active', item === option);
                    });
                    submitButton.disabled = false;
                });
            });

            form.addEventListener('submit', function(event) {
                event.preventDefault();

                if (!scoreInput.value) {
                    message.hidden = false;
                    message.textContent = 'Pilih rating pengiriman terlebih dahulu.';
                    message.className = 'public-track-feedback-message is-error';
                    return;
                }

                submitButton.disabled = true;

                fetch(@json(route('track.rate', $barang)), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({
                        score: Number(scoreInput.value),
                        review: document.getElementById('publicTrackReview').value,
                    }),
                })
                    .then(function(response) {
                        return response.json().then(function(payload) {
                            if (!response.ok) {
                                throw new Error(payload.message || 'Gagal mengirim ulasan.');
                            }
                            return payload;
                        });
                    })
                    .then(function(payload) {
                        window.location.reload();
                    })
                    .catch(function(error) {
                        submitButton.disabled = false;
                        message.hidden = false;
                        message.textContent = error.message;
                        message.className = 'public-track-feedback-message is-error';
                    });
            });
        })();
    </script>
</body>
</html>
