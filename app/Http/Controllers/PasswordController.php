<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordController extends Controller
{
    public function forgot()
    {
        return view('auth.password', ['reset' => false]);
    }

    public function sendLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));
        // Do not reveal whether an account exists.
        return back()->with('status', 'If an account exists for this email, a password reset link has been sent.');
    }

    public function reset(Request $request, string $token)
    {
        return view('auth.password', ['reset' => true, 'token' => $token, 'email' => $request->query('email', '')]);
    }

    public function update(Request $request)
    {
        $request->validate(['token' => 'required|string', 'email' => 'required|email', 'password' => 'required|string|min:8|confirmed']);
        $status = Password::reset($request->only('email', 'password', 'password_confirmation', 'token'), function ($user, $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });
        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Your password has been reset. Please sign in.')
            : back()->withErrors(['email' => __($status)])->onlyInput('email');
    }
}
