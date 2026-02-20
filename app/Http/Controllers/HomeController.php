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
                $defaultBranch = $repo['default_branch'] ?? null;
                $branchesCollection = collect($branchesResponse->json())
                    ->map(function ($branch) use ($defaultBranch) {
                        $name = $branch['name'] ?? null;
                        return [
                            'name' => $name,
                            'is_primary' => $name && $defaultBranch && $name === $defaultBranch,
                        ];
                    })
                    ->filter(fn ($branch) => $branch['name']);

                $primary = $branchesCollection->filter(fn ($branch) => $branch['is_primary']);
                $others = $branchesCollection->reject(fn ($branch) => $branch['is_primary']);
                $branches = $primary->concat($others)->values();
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

    public function searchCommits(Request $request)
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

        $mainCommits = $this->fetchCommits($options, $token, $owner, $repo, $branch);
        if ($mainCommits['failed']) {
            return response()->json([
                'message' => 'Falha ao buscar commits no GitHub.',
                'details' => $mainCommits['details'],
            ], $mainCommits['status']);
        }

        return response()->json([
            'total' => count($mainCommits['items']),
            'commits' => $mainCommits['items'],
        ]);
    }

    private function fetchCommits(array $options, string $token, string $owner, string $repo, string $branch): array
    {
        $page = 1;
        $perPage = 100;
        $items = [];
        $raw = [];

        while (true) {
            $response = Http::withOptions($options)
                ->withToken($token)
                ->get("https://api.github.com/repos/{$owner}/{$repo}/commits", [
                    'sha' => $branch,
                    'per_page' => $perPage,
                    'page' => $page,
                ]);

            if ($response->failed()) {
                return [
                    'failed' => true,
                    'status' => $response->status(),
                    'details' => $response->json(),
                ];
            }

            $batch = $response->json();
            if (empty($batch)) {
                break;
            }

            foreach ($batch as $commit) {
                $raw[] = $commit;
                $message = $commit['commit']['message'] ?? null;
                $author = $commit['commit']['author']['name'] ?? null;
                $firstLine = trim(strtok($message ?? '', "\n") ?: '');

                if (!$firstLine) {
                    continue;
                }
                if (!$author) {
                    continue;
                }
                if (preg_match('/^Merge\s/i', $firstLine)) {
                    continue;
                }

                $items[] = [
                    'sha' => $commit['sha'] ?? null,
                    'message' => $message,
                    'date' => $commit['commit']['author']['date'] ?? null,
                    'author' => $author,
                ];
            }

            $page++;
        }

        $items = array_reverse($items);
        $raw = array_reverse($raw);

        return [
            'failed' => false,
            'items' => $items,
            'raw' => $raw,
        ];
    }
}
