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

        $repos = collect($allRepos)->map(function ($repo) {
            return [
                'owner' => $repo['owner']['login'] ?? null,
                'name' => $repo['name'],
                'default_branch' => $repo['default_branch'] ?? null,
            ];
        })->values();

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

        // Buscar repositório para pegar default_branch
        $repoResponse = Http::withOptions($options)
            ->withToken($token)
            ->get("https://api.github.com/repos/{$owner}/{$repo}");

        if ($repoResponse->failed()) {
            return response()->json([
                'message' => 'Falha ao buscar repositório.',
            ], $repoResponse->status());
        }

        $branch = $repoResponse->json('default_branch');

        if (!$branch) {
            return response()->json([
                'message' => 'Branch principal não encontrada.',
            ], 422);
        }

        $commitsResponse = $this->fetchCommits($options, $token, $owner, $repo, $branch);

        if ($commitsResponse['failed']) {
            return response()->json([
                'message' => 'Falha ao buscar commits.',
            ], $commitsResponse['status']);
        }

        $commits = $commitsResponse['items'];

        return response()->json([
            'branch' => $branch,
            'total_commits' => count($commits),
            'commits' => $commits,
        ]);
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
        if (empty($commits)) {
            return response()->json(['message' => 'Nenhum commit encontrado para processar.'], 422);
        }

        $documentation = $this->sendDocToIA($options, $commits);

        if ($documentation['failed']) {
            return response()->json([
                'message' => 'Falha ao gerar documentação.',
                'details' => $documentation['details'],
            ], $documentation['status']);
        }

        return response()->json([
            'documentation' => $documentation['text'],
        ]);
    }

    private function sendDocToIA(array $options, array $commits): array
    {
        $apiKey = config('services.cohere.key');

        if (!$apiKey) {
            return [
                'failed' => true,
                'status' => 500,
                'details' => 'COHERE_API_KEY não configurada.',
            ];
        }

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
            ->take(1000) // Iremos pegar os 1000 últimos commits, senão irá demorar muito a geração mesmo com chunk
            ->values()
            ->all();

        if (empty($filtered)) {
            return [
                'failed' => true,
                'status' => 422,
                'details' => 'Nenhum commit válido.',
            ];
        }

        $chunks = array_chunk($filtered, 100);
        $partialSummaries = [];

        foreach ($chunks as $chunk) {

            \Log::info('Chunk enviado para IA', [
                'chunk_size' => count($chunk)
            ]);

            $prompt = view('prompts.partial_doc')->render();

            $payload = [
                'model' => config('services.cohere.model', 'command-a-03-2025'),
                'messages' => [
                    ['role' => 'system', 'content' => $prompt],
                    [
                        'role' => 'user',
                        'content' => implode("\n", $chunk),
                    ],
                ],
            ];

            try {
                $response = Http::withOptions($options)
                    ->timeout(240)
                    ->connectTimeout(30)
                    ->withHeaders([
                        'Authorization' => "Bearer {$apiKey}",
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->post(config('services.cohere.endpoint', 'https://api.cohere.ai/v2/chat'), $payload);
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

            $body = $response->json();
            $text = $body['message']['content'][0]['text'] ?? null;

            if (!$text) {
                return [
                    'failed' => true,
                    'status' => 502,
                    'details' => $body,
                ];
            }

            $partialSummaries[] = $text;
        }

        // Consolidação final
        $finalPrompt = view('prompts.general_doc')->render();

        $finalPayload = [
            'model' => config('services.cohere.model', 'command-a-03-2025'),
            'messages' => [
                ['role' => 'system', 'content' => $finalPrompt],
                [
                    'role' => 'user',
                    'content' => implode("\n\n", $partialSummaries),
                ],
            ],
        ];

        try {
            $finalResponse = Http::withOptions($options)
                ->timeout(240)
                ->connectTimeout(30)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post(config('services.cohere.endpoint', 'https://api.cohere.ai/v2/chat'), $finalPayload);
        } catch (\Exception $e) {
            return [
                'failed' => true,
                'status' => 500,
                'details' => $e->getMessage(),
            ];
        }

        if (!$finalResponse->ok()) {
            return [
                'failed' => true,
                'status' => $finalResponse->status(),
                'details' => $finalResponse->body(),
            ];
        }

        $body = $finalResponse->json();
        $text = $body['message']['content'][0]['text'] ?? null;

        if (!$text) {
            return [
                'failed' => true,
                'status' => 502,
                'details' => $body,
            ];
        }

        return [
            'failed' => false,
            'text' => $text,
        ];
    }

    private function fetchCommits(array $options, string $token, string $owner, string $repo, string $branch): array
    {
        $page = 1;
        $perPage = 100;
        $items = [];

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

    public function generateChangelog(Request $request)
    {
        set_time_limit(300);

        $request->validate([
            'branch' => 'required|string',
            'commits' => 'required|array|min:1',
        ]);

        $options = [];
        if (config('app.env') === 'homolog') {
            $options['verify'] = false;
        }

        $commits = collect($request->input('commits', []))
            ->filter(function ($commit) {
                $message = $commit['message'] ?? null;

                if (!is_string($message)) return false;

                $message = trim($message);

                if ($message === '') return false;

                if (preg_match('/^Merge\s/i', $message)) return false;

                return !empty($commit['date']);
            })
            ->sortByDesc('date')
            ->groupBy(function ($commit) {
                return substr($commit['date'], 0, 10);
            })
            ->map(function ($group, $date) {
                return [
                    'date' => $date,
                    'commits' => $group->pluck('message')->values()->all()
                ];
            })
            ->take(200)
            ->values()
            ->all();

        $payload = [
            'branch' => $request->input('branch'),
            'commits_by_date' => $commits,
        ];

        $result = $this->sendChangeLogToIA($options, $payload);

        if ($result['failed']) {
            return response()->json([
                'message' => 'Falha ao gerar changelog.',
                'details' => $result['details'] ?? null,
            ], $result['status']);
        }

        return response()->json([
            'changelog' => $result['text'],
        ]);
    }

    private function sendChangeLogToIA(array $options, array $payload): array
    {
        $apiKey = config('services.cohere.key');

        if (!$apiKey) {
            return [
                'failed' => true,
                'status' => 500,
                'details' => 'COHERE_API_KEY não configurada.',
            ];
        }

        $prompt = view('prompts.changelog')->render();

        $requestPayload = [
            'model' => config('services.cohere.model', 'command-a-03-2025'),
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                [
                    'role' => 'user',
                    'content' => json_encode($payload),
                ],
            ],
        ];

        try {
            $response = Http::withOptions($options)
                ->timeout(240)
                ->connectTimeout(30)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post(config('services.cohere.endpoint', 'https://api.cohere.ai/v2/chat'), $requestPayload);
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

        $body = $response->json();
        $text = $body['message']['content'][0]['text'] ?? null;

        if (!$text) {
            return [
                'failed' => true,
                'status' => 502,
                'details' => $body,
            ];
        }

        return [
            'failed' => false,
            'text' => $text,
        ];
    }
}




