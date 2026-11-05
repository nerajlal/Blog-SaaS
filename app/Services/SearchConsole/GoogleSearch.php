<?php

namespace App\Services\SearchConsole;

use App\Models\Project;
use App\Models\SearchConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class GoogleSearch
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    public function configured(): bool
    {
        return (bool) (config('search_console.client_id') && config('search_console.client_secret') && config('search_console.redirect_uri'));
    }

    public function origin(Project $project): string
    {
        abort_unless($project->seo_verified_at && ! empty($project->seo_settings['public_url']), 409, 'Verify the project public origin first.');

        return rtrim($project->seo_settings['public_url'], '/');
    }

    public function active(Project $project): SearchConnection
    {
        $connection = SearchConnection::where('project_id', $project->id)->where('state', 'connected')->first();
        abort_unless($connection && $connection->origin === $this->origin($project), 409, 'Connect Search Console for the current verified origin.');

        return $connection;
    }

    private function tokenRequest(array $data): array
    {
        abort_unless($this->configured(), 409, 'Configure Google OAuth environment settings first.');
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()->post('https://oauth2.googleapis.com/token', $data + ['client_id' => config('search_console.client_id'), 'client_secret' => config('search_console.client_secret')]);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['google' => 'Google token service is unavailable. Retry later.']);
        }
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw ValidationException::withMessages(['google' => 'Google authorization expired or was rejected. Reconnect the account.']);
        }
        $tokens = $response->json();
        if (isset($tokens['scope']) && ! in_array(self::SCOPE, explode(' ', $tokens['scope']), true)) {
            throw ValidationException::withMessages(['google' => 'Google did not grant the required read-only Search Console permission.']);
        }

        return $tokens;
    }

    public function exchange(string $code): array
    {
        return $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => config('search_console.redirect_uri')]);
    }

    public function access(SearchConnection $connection): string
    {
        return DB::transaction(function () use ($connection) {
            $current = SearchConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->state === 'connected', 409, 'Connection was disconnected.');
            if ($current->expires_at?->gt(now()->addMinute()) && $current->access_token) {
                return $current->access_token;
            }
            if (! $current->refresh_token) {
                throw ValidationException::withMessages(['google' => 'Refresh permission is unavailable. Reconnect Google.']);
            }
            $tokens = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $current->refresh_token]);
            $current->fill(['access_token' => $tokens['access_token'], 'expires_at' => now()->addSeconds(max(60, min(86400, (int) ($tokens['expires_in'] ?? 3600))))]);
            if (! empty($tokens['refresh_token'])) {
                $current->refresh_token = $tokens['refresh_token'];
            }
            $current->save();

            return $current->access_token;
        });
    }

    public function request(SearchConnection $connection, string $method, string $url, array $data = []): array
    {
        try {
            $request = Http::withToken($this->access($connection))->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting();
            $response = $method === 'GET' ? $request->get($url) : $request->post($url, $data);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['google' => 'Google could not be reached. Retry later.']);
        }
        if (! $response->successful()) {
            $message = match ($response->status()) {
                401 => 'Google authorization expired. Reconnect your account.',403 => 'Google denied access. Check property permissions and API enablement.',429 => 'Google quota reached. Retry later.',default => 'Google returned a temporary or unsupported response. Retry later.'
            };
            throw ValidationException::withMessages(['google' => $message]);
        }

        return $response->json() ?? [];
    }

    public function covers(string $property, string $origin): bool
    {
        $host = parse_url($origin, PHP_URL_HOST);
        if (str_starts_with($property, 'sc-domain:')) {
            $domain = substr($property, 10);

            return $domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain));
        }

        return $property === $origin.'/'; // This release accepts root URL-prefix properties only.
    }

    public function properties(SearchConnection $connection): array
    {
        $data = $this->request($connection, 'GET', 'https://www.googleapis.com/webmasters/v3/sites');

        return array_values(array_filter($data['siteEntry'] ?? [], fn ($site) => in_array($site['permissionLevel'] ?? '', ['siteOwner', 'siteFullUser', 'siteRestrictedUser'], true) && $this->covers($site['siteUrl'] ?? '', $connection->origin)));
    }

    public function verifyProperty(SearchConnection $connection): void
    {
        $properties = $this->properties($connection);
        if (! collect($properties)->contains(fn ($site) => $site['siteUrl'] === $connection->property)) {
            throw ValidationException::withMessages(['google' => 'The connected account no longer has access to the selected property.']);
        }
    }

    public function revoke(SearchConnection $connection): bool
    {
        try {
            $token = $connection->refresh_token ?: $connection->access_token;
            if (! $token) {
                return true;
            }

            return Http::asForm()->timeout(10)->connectTimeout(5)->withoutRedirecting()->post('https://oauth2.googleapis.com/revoke', ['token' => $token])->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
