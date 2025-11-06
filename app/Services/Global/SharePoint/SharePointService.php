<?php

namespace App\Services\Global\SharePoint;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class SharePointService
{
    protected function getTenantId(): string
    {
        $tenant = config('services.microsoft.tenant_id');
        if (!$tenant) {
            throw new \RuntimeException('Missing MS_TENANT_ID configuration.');
        }
        return $tenant;
    }

    protected function getClientId(): string
    {
        $clientId = config('services.microsoft.client_id');
        if (!$clientId) {
            throw new \RuntimeException('Missing MS_CLIENT_ID configuration.');
        }
        return $clientId;
    }

    protected function getClientSecret(): string
    {
        $secret = config('services.microsoft.client_secret');
        if (!$secret) {
            throw new \RuntimeException('Missing MS_CLIENT_SECRET configuration.');
        }
        return $secret;
    }

    protected function getSiteId(?string $siteId = null): string
    {
        $siteId = $siteId ?: config('services.microsoft.sharepoint_site_id');
        if (!$siteId) {
            throw new \RuntimeException('Missing MS_SHAREPOINT_SITE_ID configuration.');
        }
        return $siteId;
    }

    protected function tokenCacheKey(): string
    {
        return 'ms_graph_access_token';
    }

    public function getAccessToken(): string
    {
        $cached = Cache::get($this->tokenCacheKey());
        if ($cached) {
            return $cached;
        }

        $tenant = $this->getTenantId();
        $url = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";

        $response = Http::asForm()->post($url, [
            'client_id' => $this->getClientId(),
            'client_secret' => $this->getClientSecret(),
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);

        if (!$response->ok()) {
            Log::error('MS Graph token request failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Failed to obtain Microsoft Graph access token.');
        }

        $data = $response->json();
        $token = $data['access_token'] ?? null;
        $expiresIn = (int)($data['expires_in'] ?? 3600);
        if (!$token) {
            throw new \RuntimeException('Microsoft Graph response missing access_token.');
        }

        Cache::put($this->tokenCacheKey(), $token, now()->addSeconds(max(300, $expiresIn - 60)));
        return $token;
    }

    /**
     * Upload a file to SharePoint using Microsoft Graph.
     * Handles small files via simple upload and large files via upload session.
     *
     * @param UploadedFile $file
     * @param string $directory SharePoint folder path under drive root (e.g., "uploads/borrowers")
     * @param string|null $filename Optional filename; defaults to UUID + original extension
     * @param string|null $siteId SharePoint site ID; if null, uses configured default
     * @return array Drive item metadata
     */
    public function uploadFile(UploadedFile $file, string $directory = 'uploads', ?string $filename = null, ?string $siteId = null): array
    {
        $token = $this->getAccessToken();
        $siteId = $this->getSiteId($siteId);

        $dir = trim($directory, '/');
        $name = $filename ?: (Str::uuid()->toString() . ($file->getClientOriginalExtension() ? '.' . $file->getClientOriginalExtension() : ''));
        $targetPath = $dir ? ($dir . '/' . $name) : $name;

        $size = (int) $file->getSize();
        $contents = file_get_contents($file->getRealPath());

        // Simple upload for <= 4 MB
        if ($size <= 4 * 1024 * 1024) {
            $url = "https://graph.microsoft.com/v1.0/sites/{$siteId}/drive/root:/" . rawurlencode($targetPath) . ":/content";
            $response = Http::withToken($token)
                ->withHeaders(['Content-Type' => 'application/octet-stream'])
                ->put($url, $contents);

            if (!$response->ok()) {
                Log::error('SharePoint small upload failed', ['status' => $response->status(), 'body' => $response->body()]);
                throw new \RuntimeException('SharePoint upload failed.');
            }

            return $this->mapDriveItem($response->json());
        }

        // Large upload via session
        $sessionUrl = "https://graph.microsoft.com/v1.0/sites/{$siteId}/drive/root:/" . rawurlencode($targetPath) . ":/createUploadSession";
        $session = Http::withToken($token)->post($sessionUrl, [
            'item' => [
                '@microsoft.graph.conflictBehavior' => 'rename',
                'name' => $name,
            ],
        ]);

        if (!$session->ok()) {
            Log::error('SharePoint createUploadSession failed', ['status' => $session->status(), 'body' => $session->body()]);
            throw new \RuntimeException('Failed to create SharePoint upload session.');
        }

        $uploadUrl = $session->json()['uploadUrl'] ?? null;
        if (!$uploadUrl) {
            throw new \RuntimeException('Upload session missing uploadUrl.');
        }

        $chunkSize = 5 * 1024 * 1024; // 5 MB
        $offset = 0;
        $total = strlen($contents);
        while ($offset < $total) {
            $length = min($chunkSize, $total - $offset);
            $chunk = substr($contents, $offset, $length);
            $end = $offset + $length - 1;
            $headers = [
                'Content-Length' => (string) $length,
                'Content-Range' => "bytes {$offset}-{$end}/{$total}",
            ];

            $resp = Http::withHeaders($headers)->send('PUT', $uploadUrl, [
                'body' => $chunk,
            ]);

            if ($resp->failed()) {
                Log::error('SharePoint chunk upload failed', ['status' => $resp->status(), 'body' => $resp->body(), 'range' => $headers['Content-Range']]);
                throw new \RuntimeException('SharePoint chunk upload failed.');
            }

            // Final response contains the drive item
            if (in_array($resp->status(), [200, 201])) {
                return $this->mapDriveItem($resp->json());
            }

            $offset += $length;
        }

        throw new \RuntimeException('SharePoint upload did not return a completed item.');
    }

    /**
     * Delete a SharePoint drive item by its ID.
     */
    public function deleteItemById(string $itemId, ?string $siteId = null): bool
    {
        $token = $this->getAccessToken();
        $siteId = $this->getSiteId($siteId);
        $url = "https://graph.microsoft.com/v1.0/sites/{$siteId}/drive/items/{$itemId}";

        $response = Http::withToken($token)->delete($url);
        if ($response->noContent() || $response->ok()) {
            return true;
        }
        Log::warning('SharePoint delete by ID failed', [
            'status' => $response->status(),
            'body' => $response->body(),
            'itemId' => $itemId,
        ]);
        return false;
    }

    /**
     * Delete a SharePoint drive item by its path under root.
     * Path example: "uploads/borrowers/file.ext".
     */
    public function deleteItemByPath(string $path, ?string $siteId = null): bool
    {
        $token = $this->getAccessToken();
        $siteId = $this->getSiteId($siteId);
        $cleanPath = trim($path, '/');
        $url = "https://graph.microsoft.com/v1.0/sites/{$siteId}/drive/root:/" . rawurlencode($cleanPath) . ":";

        $response = Http::withToken($token)->delete($url);
        if ($response->noContent() || $response->ok()) {
            return true;
        }
        Log::warning('SharePoint delete by path failed', [
            'status' => $response->status(),
            'body' => $response->body(),
            'path' => $cleanPath,
        ]);
        return false;
    }

    protected function mapDriveItem(array $item): array
    {
        return [
            'id' => $item['id'] ?? null,
            'name' => $item['name'] ?? null,
            'size' => $item['size'] ?? null,
            'webUrl' => $item['webUrl'] ?? null,
            'downloadUrl' => $item['@microsoft.graph.downloadUrl'] ?? null,
            'parentReference' => $item['parentReference'] ?? [],
        ];
    }
}