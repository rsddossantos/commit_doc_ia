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

    public function processMain(Request $request)
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

    public function generateDocumentation(Request $request)
    {
        set_time_limit(300);
        $request->validate([
            'owner' => 'required|string',
            'repo' => 'required|string',
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

        $payload = [
            'owner' => $request->input('owner'),
            'repo' => $request->input('repo'),
            'branch' => $request->input('branch'),
            'total' => count($commits),
            'commits' => $commits,
        ];

        $documentation = $this->sendCommitsToIA($options, $payload);
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

    private function sendCommitsToIA(array $options, array $commitPayload): array
    {
        $apiKey = config('services.cohere.key');
        if (!$apiKey) {
            return [
                'failed' => true,
                'status' => 500,
                'details' => 'COHERE_API_KEY não configurada.',
            ];
        }

        $initialPrompt = view('prompts.commit_doc')->render();
        $messages = collect($commitPayload['commits'] ?? [])
            ->pluck('message')
            ->filter(fn ($message) => is_string($message) && trim($message) !== '')
            ->values()
            ->all();

        $commitData = [
            'owner' => $commitPayload['owner'] ?? null,
            'repo' => $commitPayload['repo'] ?? null,
            'branch' => $commitPayload['branch'] ?? null,
            'total' => $commitPayload['total'] ?? count($messages),
            'commit_messages' => $messages,
        ];

        $payload = [
            'model' => config('services.cohere.model', 'command-a-03-2025'),
            'messages' => [
                ['role' => 'system', 'content' => $initialPrompt],
                [
                    'role' => 'user',
                    'content' => "Dados do repositório em JSON (apenas mensagens de commits):\n" . json_encode($commitData['commit_messages']),
                ],
            ],
        ];

        try {
            $response = Http::withOptions($options)
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

    public function processFeature(Request $request)
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
        $feature = $request->input('branch');

        // Buscar default branch
        $repoResponse = Http::withOptions($options)
            ->withToken($token)
            ->get("https://api.github.com/repos/{$owner}/{$repo}");

        if ($repoResponse->failed()) {
            return response()->json([
                'message' => 'Falha ao buscar repositório.',
                'details' => $repoResponse->json(),
            ], $repoResponse->status());
        }

        $base = $repoResponse->json('default_branch');

        if (!$base) {
            return response()->json([
                'message' => 'Branch base não encontrada.',
            ], 422);
        }

        // Compare base...feature
        $compareResponse = Http::withOptions($options)
            ->withToken($token)
            ->get("https://api.github.com/repos/{$owner}/{$repo}/compare/{$base}...{$feature}");

        if ($compareResponse->failed()) {
            return response()->json([
                'message' => 'Falha ao comparar branches.',
                'details' => $compareResponse->json(),
            ], $compareResponse->status());
        }

        $compareData = $compareResponse->json();

        $commits = collect($compareData['commits'] ?? [])
            ->map(function ($commit) {
                return [
                    'sha' => $commit['sha'] ?? null,
                    'message' => $commit['commit']['message'] ?? null,
                    'date' => $commit['commit']['author']['date'] ?? null,
                    'author' => $commit['commit']['author']['name'] ?? null,
                ];
            })
            ->values()
            ->all();

        $files = collect($compareData['files'] ?? [])
            ->map(function ($file) {
                return [
                    'filename' => $file['filename'] ?? null,
                    'status' => $file['status'] ?? null,
                    'additions' => $file['additions'] ?? 0,
                    'deletions' => $file['deletions'] ?? 0,
                    'changes' => $file['changes'] ?? 0,
                    'patch' => $file['patch'] ?? null,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'base' => $base,
            'feature' => $feature,
            'total_commits' => count($commits),
            'total_files' => count($files),
            'commits' => $commits,
            'files' => $files,
        ]);
    }

    public function generateChangelog(Request $request)
    {
        set_time_limit(300);

        $request->validate([
            'owner' => 'required|string',
            'repo' => 'required|string',
            'branch' => 'required|string',
            'base' => 'required|string',
            'commits' => 'required|array',
            'files' => 'required|array',
        ]);

        $token = $request->session()->get('github_token');
        if (!$token) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $options = [];
        if (config('app.env') === 'homolog') {
            $options['verify'] = false;
        }

        $apiKey = config('services.cohere.key');
        if (!$apiKey) {
            return response()->json([
                'message' => 'COHERE_API_KEY não configurada.',
            ], 500);
        }

        $initialPrompt = view('prompts.changelog_doc')->render();

        $payloadData = [
            'owner' => $request->input('owner'),
            'repo' => $request->input('repo'),
            'base' => $request->input('base'),
            'feature' => $request->input('branch'),
            'commits' => $request->input('commits'),
            'files' => $request->input('files'),
        ];

        $payload = [
            'model' => config('services.cohere.model', 'command-a-03-2025'),
            'messages' => [
                ['role' => 'system', 'content' => $initialPrompt],
                [
                    'role' => 'user',
                    'content' => "Dados da comparação em JSON:\n" . json_encode($payloadData),
                ],
            ],
        ];

        try {
            $response = Http::withOptions($options)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->post(config('services.cohere.endpoint', 'https://api.cohere.ai/v2/chat'), $payload);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erro ao conectar com IA.',
                'details' => $e->getMessage(),
            ], 500);
        }

        if (!$response->ok()) {
            return response()->json([
                'message' => 'Falha ao gerar changelog.',
                'details' => $response->body(),
            ], $response->status());
        }

        $body = $response->json();
        $text = $body['message']['content'][0]['text'] ?? null;

        if (!$text) {
            return response()->json([
                'message' => 'Resposta inválida da IA.',
                'details' => $body,
            ], 502);
        }

        return response()->json([
            'changelog' => $text,
        ]);
    }
}




