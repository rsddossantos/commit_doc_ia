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

        $userResponse = Http::withOptions($options)
            ->withToken($token)
            ->get('https://api.github.com/user');

        if ($userResponse->failed()) {
            return redirect()->route('login');
        }

        $page = 1;
        $perPage = 100; // Github permite máximo de 100
        $allRepos = [];

        while (true) {
            $reposResponse = Http::withOptions($options)
                ->withToken($token)
                ->get('https://api.github.com/user/repos', [
                    'per_page' => $perPage,
                    'page' => $page
                ]);

            if ($reposResponse->failed()) {
                return redirect()->route('login');
            }

            $batch = $reposResponse->json();
            if (empty($batch)) {
                break;
            }

            $allRepos = array_merge($allRepos, $batch);
            $page++;
        }

        $repos = collect($allRepos)->map(function ($repo) use ($token, $options) {
            $branchesResponse = Http::withOptions($options)
                ->withToken($token)
                ->get(str_replace('{/branch}', '', $repo['branches_url']));
            $branches = [];
            if (!$branchesResponse->failed()) {
                $branches = collect($branchesResponse->json())->pluck('name');
            }
            return [
                'owner' => $repo['owner']['login'] ?? null,
                'name' => $repo['name'],
                'branches' => $branches,
            ];
        });

        return Inertia::render('HomePage', [
            'repos' => $repos,
            'user' => [
                'name' => $userResponse->json('name'),
                'login' => $userResponse->json('login'),
            ],
        ]);
    }

    public function commits(Request $request)
    {
        set_time_limit(300);
        $request->validate([
            'owner' => 'required|string',
            'repo' => 'required|string',
            'branch' => 'required|string',
        ]);

        $token = $request->session()->get('github_token');
        if (!$token) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $options = [];
        if (config('app.env') === 'homolog') {
            $options['verify'] = false;
        }

        $owner = $request->input('owner');
        $repo = $request->input('repo');
        $branch = $request->input('branch');

        $page = 1;
        $perPage = 100;
        $allCommits = [];

        while (true) {
            $response = Http::withOptions($options)
                ->withToken($token)
                ->get("https://api.github.com/repos/{$owner}/{$repo}/commits", [
                    'sha' => $branch,
                    'per_page' => $perPage,
                    'page' => $page,
                ]);

            if ($response->failed()) {
                return response()->json([
                    'message' => 'Falha ao buscar commits no GitHub.',
                    'details' => $response->json(),
                ], $response->status());
            }

            $batch = $response->json();
            if (empty($batch)) {
                break;
            }

            foreach ($batch as $commit) {
                $allCommits[] = [
                    'sha' => $commit['sha'] ?? null,
                    'message' => $commit['commit']['message'] ?? null,
                    'date' => $commit['commit']['author']['date'] ?? null,
                    'author' => $commit['commit']['author']['name'] ?? null,
                ];
            }

            $page++;
        }

        $allCommits = array_reverse($allCommits);

        return response()->json([
            'total' => count($allCommits),
            'commits' => $allCommits,
        ]);
    }
}
