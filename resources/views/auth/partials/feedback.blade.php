@php
    $authStatus = session('status');
    $authStatusTitle = 'Success';
    if ($authStatus === 'registration-success') {
        $authStatusTitle = 'Registration Successful!';
    } elseif ($authStatus === 'Your password has been reset successfully. You can now log in.') {
        $authStatusTitle = 'Password Reset Successful';
    } elseif ($authStatus === 'A verification code has been sent to your email.') {
        $authStatusTitle = 'Verification Code Sent';
    } elseif ($authStatus === \App\Http\Controllers\PasswordResetController::SENT_MESSAGE) {
        $authStatusTitle = 'Password Reset Request Received';
    }
    $authErrorTitle = null;
    $authErrorMessage = null;
    if ($errors->has('email')) {
        $loginError = $errors->first('email');
        if (in_array($loginError, [
            'The provided credentials do not match our records.',
            \App\Models\User::DEACTIVATED_MESSAGE,
        ], true)) {
            $authErrorTitle = 'Login Failed';
            $authErrorMessage = 'The username/email or password you entered is incorrect. Please check your credentials and try again.';
        }
    }

    if ($errors->has('code')) {
        $authErrorTitle = 'Verification Code Not Accepted';
        $authErrorMessage = $errors->first('code');
    } elseif ($errors->has('two_factor')) {
        $authErrorTitle = 'Verification Code Request';
        $authErrorMessage = $errors->first('two_factor');
    } elseif ($errors->has('email')) {
        $passwordRequestError = $errors->first('email');
        if (str_starts_with($passwordRequestError, 'Password reset emails are temporarily unavailable.')) {
            $authErrorTitle = 'Password Reset Unavailable';
            $authErrorMessage = $passwordRequestError;
        }
    }

    if ($errors->has('reset') || $errors->has('token')) {
        $authErrorTitle = 'Password Reset Failed';
        $authErrorMessage = $errors->first('reset') ?: $errors->first('token');
    }
@endphp

@if ($authStatus)
    <div class="reservation-alert" role="status">
        @if ($authStatus === 'registration-success')
            <strong>Registration Successful!</strong><br>
            Your account has been created successfully. You can now log in using your registered username or email address and password.
        @else
            <strong>{{ $authStatusTitle }}</strong><br>
            {{ $authStatus }}
        @endif
    </div>
@endif

@if ($authErrorMessage)
    <div class="reservation-alert reservation-alert-error" role="alert">
        <strong>{{ $authErrorTitle }}</strong><br>
        {{ $authErrorMessage }}
    </div>
@endif
