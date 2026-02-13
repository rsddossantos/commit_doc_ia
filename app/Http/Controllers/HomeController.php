<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        set_time_limit(120);
        $token = $request->session()->get('github_token');

        if (!$token) {
            return redirect()->route('login');
        }

        $options = [];
        if (config('app.env') === 'homolog') {
            $options['verify'] = false;
        }

        $reposResponse = Http::withOptions($options)
            ->withToken($token)
            ->get('https://api.github.com/user/repos', [
                'per_page' => 20,
                'page' => 1
            ]);

        if ($reposResponse->failed()) {
            return redirect()->route('login');
        }

        $repos = collect($reposResponse->json())->map(function ($repo) use ($token, $options) {
            $branchesResponse = Http::withOptions($options)
                ->withToken($token)
                ->get(str_replace('{/branch}', '', $repo['branches_url']));
            $branches = [];
            if (!$branchesResponse->failed()) {
                $branches = collect($branchesResponse->json())->pluck('name');
            }
            return [
                'name' => $repo['name'],
                'branches' => $branches,
            ];
        });

        return Inertia::render('HomePage', [
            'repos' => $repos
        ]);
    }


}
