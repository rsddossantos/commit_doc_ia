<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $token = $request->input('token');

        $options = [];
        if (config('app.env') === 'homolog') {
            $options['verify'] = false;
        }

        $response =  Http::withOptions($options)
            ->withToken($token)
            ->get('https://api.github.com/user');

        if ($response->failed()) {
            return Inertia::render('LoginPage', [
                'error' => 'Token inválido ou não autorizado.'
            ]);
        }

        $request->session()->put('github_token', $token);

        return redirect()->route('home');
    }

    public function logout(Request $request)
    {
        $request->session()->forget('github_token');
        return redirect()->route('login');
    }
}
