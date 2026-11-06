@extends('layouts.auth')
@section('title', ($reset ? 'Reset password' : 'Forgot password').' — BlogSaaS')
@section('auth_navigation')<span>Remember your password?</span><a href="{{ route('login') }}">Sign in <span aria-hidden="true">↗</span></a>@endsection
@section('auth_eyebrow', 'A little help getting home')
@section('auth_heading', $reset ? 'A fresh start.' : 'Let’s get you back.')
@section('auth_description', $reset ? 'Choose a new password and make room for what comes next.' : 'Enter your email and we’ll send a link to reset your password.')
@section('auth_form')
<form action="{{ $reset ? route('password.update') : route('password.email') }}" method="POST" class="auth-form">
    @csrf
    @if($reset)<input type="hidden" name="token" value="{{ $token }}">@endif
    <div class="product-field"><label for="email">Email address</label><input id="email" type="email" name="email" autocomplete="email" required value="{{ old('email', $email ?? '') }}" placeholder="you@yourstudio.com"></div>
    @if($reset)
    <div class="product-field"><label for="password">New password</label><div class="password-field"><input id="password" type="password" name="password" autocomplete="new-password" minlength="8" required placeholder="At least 8 characters"><button type="button" data-toggle-password="password" aria-label="Show password">Show</button></div></div>
    <div class="product-field"><label for="password-confirmation">Confirm password</label><input id="password-confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required placeholder="One more time"></div>
    @endif
    <button type="submit" class="product-button product-button-dark auth-submit">{{ $reset ? 'Reset password' : 'Send reset link' }} <span aria-hidden="true">↗</span></button>
</form>
@endsection
@section('auth_footer')<p class="auth-form-footer"><a href="{{ route('login') }}">← Back to sign in</a></p>@endsection
