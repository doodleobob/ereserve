@props(['value' => null, 'required' => false, 'groupClass' => 'profile-group'])
<div class="{{ $groupClass }}">
    <label for="phone_number">Phone Number @if ($required)<span>*</span>@endif</label>
    <input id="phone_number" name="phone_number" type="tel" inputmode="tel" autocomplete="tel"
        value="{{ old('phone_number', $value) }}" placeholder="09XXXXXXXXX" maxlength="16"
        aria-describedby="phone-number-hint" @required($required)>
    <small id="phone-number-hint">Use 09XXXXXXXXX or +639XXXXXXXXX.</small>
    @error('phone_number')
        <p class="form-error" role="alert">{{ $message }}</p>
    @enderror
</div>
