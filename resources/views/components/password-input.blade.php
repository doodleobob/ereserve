@props(['id', 'name', 'placeholder', 'autocomplete', 'label' => 'password'])

<div class="password-field">
    <input id="{{ $id }}" name="{{ $name }}" type="password" placeholder="{{ $placeholder }}" autocomplete="{{ $autocomplete }}" required>
    <button type="button" class="password-toggle" data-password-toggle aria-controls="{{ $id }}" aria-label="Show {{ $label }}" data-show-label="Show {{ $label }}" data-hide-label="Hide {{ $label }}" hidden>
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
            <circle cx="12" cy="12" r="3" />
            <path class="password-eye-slash" d="m3 3 18 18" />
        </svg>
    </button>
</div>
