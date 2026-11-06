@extends('layouts.auth')
@section('title', 'Start your next chapter — BlogSaaS')
@section('auth_navigation')<span>Already feel at home?</span><a href="{{ route('login') }}">Sign in <span aria-hidden="true">↗</span></a>@endsection
@section('auth_eyebrow', 'Make yourself at home')
@section('auth_heading', 'A fresh chapter.')
@section('auth_description', 'Create your workspace. Give your next big idea a home.')
@section('auth_form')
<form action="{{ route('register.post') }}" method="POST" class="auth-form">
    @csrf
    <div class="auth-field-grid"><div class="product-field"><label for="name">Your name</label><input id="name" name="name" autocomplete="name" value="{{ old('name') }}" placeholder="Jamie Davis" maxlength="255" required autofocus></div><div class="product-field"><label for="company-name">Workspace name <span>(optional)</span></label><input id="company-name" name="company_name" autocomplete="organization" value="{{ old('company_name') }}" placeholder="Studio journal" maxlength="255"></div></div>
    <div class="product-field"><label for="email">Email address</label><input id="email" type="email" name="email" autocomplete="email" value="{{ old('email') }}" placeholder="you@yourstudio.com" maxlength="255" required></div>
    <div class="product-field"><label for="password">Password</label><div class="password-field"><input id="password" type="password" name="password" autocomplete="new-password" placeholder="At least 8 characters" minlength="8" required><button type="button" data-toggle-password="password" aria-label="Show password">Show</button></div></div>
    <div class="product-field"><label for="password-confirmation">Confirm password</label><div class="password-field"><input id="password-confirmation" type="password" name="password_confirmation" autocomplete="new-password" placeholder="One more time" minlength="8" required><button type="button" data-toggle-password="password-confirmation" aria-label="Show confirmation password">Show</button></div></div>
    <button type="submit" class="product-button product-button-dark auth-submit">Create workspace <span aria-hidden="true">↗</span></button>
</form>
@endsection
@section('auth_footer')<p class="auth-form-footer">Already have a workspace? <a href="{{ route('login') }}">Welcome back. Sign in</a></p>@endsection
