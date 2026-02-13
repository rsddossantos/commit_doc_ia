<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GitHubService
{
    public function validateToken(string $token): bool
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/vnd.github+json',
        ])->get('https://api.github.com/user');

        return $response->successful();
    }
}
