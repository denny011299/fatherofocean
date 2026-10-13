@php
    use App\Support\Locales;

    $current = app()->getLocale();
    $locales = Locales::available();
    $names = Locales::names();
    $currentName = Locales::name($current);
@endphp
<div class="dropdown locale-switcher">
    <button
        type="button"
        class="theme-toggle dropdown-toggle"
        data-bs-toggle="dropdown"
        aria-expanded="false"
        title="{{ __('common.current_language', ['name' => $currentName]) }}"
    >
        <i class="bi bi-translate"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end locale-dropdown">
        @foreach ($locales as $code)
            <li>
                <a
                    class="dropdown-item {{ $code === $current ? 'active' : '' }}"
                    href="{{ route('locale.switch', $code) }}"
                >
                    @if ($code === $current)
                        <i class="bi bi-check2 me-2"></i>
                    @else
                        <span class="locale-spacer me-2" aria-hidden="true"></span>
                    @endif
                    {{ $names[$code] }}
                </a>
            </li>
        @endforeach
    </ul>
</div>
