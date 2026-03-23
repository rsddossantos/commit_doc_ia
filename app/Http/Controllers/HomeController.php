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

        $repos = [];
        foreach ($allRepos as $repo) {
            $repos[] = [
                'owner' => $repo['owner']['login'] ?? null,
                'name' => $repo['name'],
                'default_branch' => $repo['default_branch'] ?? null,
            ];
        }

        return Inertia::render('HomePage', [
            'repos' => $repos,
            'user' => [
                'name' => $userResponse->json('name'),
                'login' => $userResponse->json('login'),
            ],
        ]);
    }

    public function processMain(Request $request)
    {
        set_time_limit(300);

        $request->validate([
            'owner' => 'required|string',
            'repo' => 'required|string',
            'default_branch' => 'required|string',
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
        $branch = $request->input('default_branch');

        $commitsResponse = $this->fetchCommits($options, [
            'token' => $token,
            'owner' => $owner,
            'repo' => $repo,
            'branch' => $branch,
        ]);

        if ($commitsResponse['failed']) {
            return response()->json([
                'message' => 'Falha ao buscar commits.',
                'details' => $commitsResponse['details']
            ], $commitsResponse['status']);
        }

        $commits = $commitsResponse['items'];

        return response()->json([
            'repo' => $repo,
            'branch' => $branch,
            'total_commits' => count($commits),
            'commits' => $commits,
        ]);
    }

    private function fetchCommits(array $options, array $data): array
    {
        $page = 1;
        $perPage = 100;
        $items = [];

        while (true) {
            $response = Http::withOptions($options)
                ->withToken($data['token'])
                ->get("https://api.github.com/repos/{$data['owner']}/{$data['repo']}/commits", [
                    'sha' => $data['branch'],
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
                $message = $commit['commit']['message'] ?? null;
                $date = $commit['commit']['author']['date'] ?? null;

                if (!$message || !$date) continue;

                $firstLine = trim(strtok($message ?? '', "\n") ?: '');

                if (!$firstLine) continue;

                if (preg_match('/^Merge\s/i', $firstLine)) continue;

                $items[] = [
                    'message' => mb_substr($firstLine, 0, 300),
                    'date' => $date,
                ];
            }

            $page++;
        }

        $items = array_reverse($items);

        return [
            'failed' => false,
            'items' => $items,
        ];
    }

    public function generateDocumentation(Request $request)
    {
        set_time_limit(300);

        $request->validate([
            'branch' => 'required|string',
            'commits' => 'required|array|min:1',
        ]);

        $token = $request->session()->get('github_token');
        if (!$token) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $options = [];
        if (config('app.env') === 'homolog') {
            $options['verify'] = false;
        }

        $commits = $request->input('commits', []);

        $filtered = collect($commits)
            ->filter(function ($commit) {
                $message = $commit['message'] ?? null;

                if (!is_string($message)) return false;

                $message = mb_substr(trim($message), 0, 300);

                if ($message === '') return false;

                if (preg_match('/^Merge\s/i', $message)) return false;

                if (empty($commit['date'])) return false;

                return true;
            })
            ->sortByDesc('date')
            ->map(function ($commit) {
                return $commit['message'];
            })
            ->take(1000)
            ->values()
            ->all();

        if (empty($filtered)) {
            return response()->json([
                'message' => 'Nenhum commit válido.',
            ], 422);
        }

        $chunks = array_chunk($filtered, 100);
        $partials = [];

        foreach ($chunks as $chunk) {

            \Log::info('Chunk enviado para IA', [
                'chunk_size' => count($chunk)
            ]);

            $result = $this->callIA(
                $options,
                view('prompts.partial_doc')->render(),
                implode("\n", $chunk)
            );

            if ($result['failed']) {
                return response()->json([
                    'message' => 'Falha ao gerar documentação.',
                    'details' => $result['details'] ?? null,
                ], $result['status']);
            }

            $partials[] = $result['text'];
        }

        $final = $this->callIA(
            $options,
            view('prompts.general_doc')->render(),
            implode("\n\n", $partials)
        );

        if ($final['failed']) {
            return response()->json([
                'message' => 'Falha ao gerar documentação.',
                'details' => $final['details'] ?? null,
            ], $final['status']);
        }

        return response()->json([
            'documentation' => $final['text'],
        ]);
    }

    public function generateChangelog(Request $request)
    {
        set_time_limit(600);

        $request->validate([
            'branch' => 'required|string',
            'commits' => 'required|array|min:1',
        ]);

        $options = [];
        if (config('app.env') === 'homolog') {
            $options['verify'] = false;
        }

        $commits = $request->input('commits', []);

        $filtered = collect($commits)
            ->filter(function ($commit) {
                $message = $commit['message'] ?? null;

                if (!is_string($message)) return false;

                $firstLine = trim(strtok($message, "\n") ?: '');
                if ($firstLine === '') return false;

                return !empty($commit['date']);
            })
            ->sortByDesc('date')
            ->take(2000)
            ->groupBy(function ($commit) {
                return date('Y-m-d', strtotime($commit['date']));
            })
            ->map(function ($group, $date) {
                $messages = $group->map(function ($commit) {
                    return trim(strtok($commit['message'], "\n") ?: '');
                });

                return "Release {$date}\n" . $messages->implode("\n");
            })
            ->values()
            ->all();

        if (empty($filtered)) {
            return response()->json([
                'message' => 'Nenhum commit válido.',
            ], 422);
        }

        $chunks = array_chunk($filtered, 100);
        $partials = [];

        foreach ($chunks as $chunk) {

            \Log::info('Chunk enviado para IA', [
                'chunk_size' => count($chunk)
            ]);

            $result = $this->callIA(
                $options,
                view('prompts.partial_changelog')->render(),
                implode("\n\n", $chunk)
            );

            if ($result['failed']) {
                return response()->json([
                    'message' => 'Falha ao gerar changelog.',
                    'details' => $result['details'] ?? null,
                ], $result['status']);
            }

            $partials[] = $result['text'];
        }

        $final = $this->callIA(
            $options,
            view('prompts.general_changelog')->render(),
            implode("\n\n", $partials)
        );

        if ($final['failed']) {
            return response()->json([
                'message' => 'Falha ao gerar changelog.',
                'details' => $final['details'] ?? null,
            ], $final['status']);
        }

        return response()->json([
            'changelog' => $final['text'],
        ]);
    }

    private function callIA(array $options, string $systemPrompt, string $userContent): array
    {
        $apiKey = config('services.cohere.key');

        if (!$apiKey) {
            return [
                'failed' => true,
                'status' => 500,
                'details' => 'COHERE_API_KEY não configurada.',
            ];
        }

        try {
            $response = Http::withOptions($options)
                ->timeout(240)
                ->connectTimeout(30)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post(config('services.cohere.endpoint', 'https://api.cohere.ai/v2/chat'), [
                    'model' => config('services.cohere.model', 'command-a-03-2025'),
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userContent],
                    ],
                ]);
        } catch (\Exception $e) {
            return [
                'failed' => true,
                'status' => 500,
                'details' => $e->getMessage(),
            ];
        }

        if (!$response->ok()) {
            return [
                'failed' => true,
                'status' => $response->status(),
                'details' => $response->body(),
            ];
        }

        $text = $response->json('message.content.0.text');

        if (!$text) {
            return [
                'failed' => true,
                'status' => 502,
                'details' => $response->json(),
            ];
        }

        return [
            'failed' => false,
            'text' => $text,
        ];
    }
}




