<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Workspace;

class AuthController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('admin.projects');
        }
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            // Ensure user has an active workspace
            $user = Auth::user();
            if (!$user->current_workspace_id) {
                $firstWorkspace = $user->workspaces()->first();
                if ($firstWorkspace) {
                    $user->current_workspace_id = $firstWorkspace->id;
                    $user->save();
                }
            }

            return redirect()->intended(route('admin.projects'));
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    public function showRegister()
    {
        if (Auth::check()) {
            return redirect()->route('admin.projects');
        }
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($request) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);

            // Create default workspace for new tenant
            $wsName = $request->filled('company_name') ? $request->company_name : $request->name . "'s Workspace";
            $slugBase = Str::slug($wsName);
            $slug = $slugBase;
            $counter = 1;
            while (Workspace::where('slug', $slug)->exists()) {
                $slug = $slugBase . '-' . $counter++;
            }

            $workspace = Workspace::create([
                'owner_id' => $user->id,
                'name' => $wsName,
                'slug' => $slug,
            ]);

            $workspace->users()->attach($user->id, ['role' => 'owner']);

            $user->current_workspace_id = $workspace->id;
            $user->save();

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('admin.projects')->with('success', 'Welcome to your SaaS Blog workspace!');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}

