@extends('layouts.auth')
@section('title', 'Welcome back — BlogSaaS')
@section('auth_navigation')<span>New around here?</span><a href="{{ route('register') }}">Create an account <span aria-hidden="true">↗</span></a>@endsection
@section('auth_eyebrow', 'Pick up where you left off')
@section('auth_heading', 'Welcome back.')
@section('auth_description', 'Your next great story is waiting. Sign in to your workspace.')
@section('auth_form')
<form action="{{ route('login.post') }}" method="POST" class="auth-form">
    @csrf
    <div class="product-field"><label for="email">Email address</label><input id="email" type="email" name="email" autocomplete="username" value="{{ old('email') }}" placeholder="you@yourstudio.com" required autofocus @error('email') aria-invalid="true" @enderror></div>
    <div class="product-field"><label for="password">Password</label><div class="password-field"><input id="password" type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required><button type="button" data-toggle-password="password" aria-label="Show password">Show</button></div></div>
    <div class="auth-form-options"><label class="product-checkbox"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Remember me</label><a href="{{ route('password.request') }}">Forgot password?</a></div>
    <button type="submit" class="product-button product-button-dark auth-submit">Sign In <span aria-hidden="true">↗</span></button>
</form>
@endsection
@section('auth_footer')<p class="auth-form-footer">A fresh start? <a href="{{ route('register') }}">Create your workspace</a></p>@endsection
