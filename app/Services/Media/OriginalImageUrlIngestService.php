<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspace\WorkspaceAuthorization;
use App\Support\Connectors\Transport\ConnectorHttpTransport;
use App\Support\Connectors\Transport\ConnectorOutboundRequest;
use App\Support\Connectors\Transport\ConnectorTransportException;
use App\Support\Connectors\Transport\ConnectorTransportLimits;
use App\Support\Workspace\WorkspacePermissions;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class OriginalImageUrlIngestService
{
    private const MAX_REDIRECTS = 3;

    private const ERROR_FIELD = 'url';

    public function __construct(
        private readonly ConnectorHttpTransport $transport,
        private readonly OriginalImageIngestService $ingest,
        private readonly WorkspaceAuthorization $authorization,
    ) {}

    public function ingest(User $actor, Workspace $workspace, string $url): MediaAsset
    {
        // Permission precedes every outbound request; there is no unauthenticated URL oracle.
        if (! $this->authorization->allows($actor, $workspace, WorkspacePermissions::MANAGE_PRODUCTS)) {
            throw new AuthorizationException('This action is unauthorized.');
        }

        if (strlen($url) > 2048 || trim($url) === '') {
            $this->invalidUrl();
        }

        try {
            $original = new Uri(trim($url));
        } catch (\Throwable) {
            $this->invalidUrl();
        }

        $this->validateUrl($original);
        $current = $original;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                // Reuses the already-certified connector HTTP transport solely as a
                // transport boundary: pinned public DNS, no automatic redirects or
                // proxies, capped decoded response, TLS verification and deadlines.
                $response = $this->transport->send(new ConnectorOutboundRequest(
                    new Request('GET', $current, ['Accept' => 'image/avif,image/webp,image/png,image/jpeg,image/gif']),
                    new ConnectorTransportLimits(
                        connectTimeoutSeconds: 3.0,
                        totalTimeoutSeconds: 15.0,
                        maxResponseBodyBytes: OriginalImageIngestService::MAX_BYTES,
                    ),
                ));
            } catch (ConnectorTransportException) {
                throw ValidationException::withMessages([
                    self::ERROR_FIELD => 'Не вдалося безпечно завантажити зображення. Перевірте публічну адресу та спробуйте ще раз.',
                ]);
            }

            if (in_array($response->statusCode, [301, 302, 303, 307, 308], true)) {
                if ($hop >= self::MAX_REDIRECTS) {
                    $this->invalidUrl();
                }

                $location = $this->header($response->headers, 'location');
                if ($location === null || $location === '') {
                    $this->invalidUrl();
                }

                try {
                    $next = UriResolver::resolve($current, new Uri($location));
                } catch (\Throwable) {
                    $this->invalidUrl();
                }

                $this->validateUrl($next);
                // Do not silently downgrade a secure image URL to HTTP.
                if ($current->getScheme() === 'https' && $next->getScheme() !== 'https') {
                    $this->invalidUrl();
                }

                $current = $next;

                continue;
            }

            if ($response->statusCode !== 200) {
                throw ValidationException::withMessages([
                    self::ERROR_FIELD => 'Сервер зображення не повернув файл. Перевірте URL.',
                ]);
            }

            $path = tempnam(sys_get_temp_dir(), 'bp_media_url_');
            if ($path === false) {
                throw ValidationException::withMessages([
                    self::ERROR_FIELD => 'Не вдалося підготувати зображення до завантаження.',
                ]);
            }

            try {
                if (file_put_contents($path, $response->body) === false) {
                    throw new \RuntimeException('Cannot write bounded image body.');
                }

                $name = basename($current->getPath());
                $name = $name !== '' && $name !== '/' ? $name : 'remote-image';
                $file = new UploadedFile($path, $name, null, UPLOAD_ERR_OK, true);

                return $this->ingest->ingestStandalone($actor, $workspace, $file);
            } finally {
                @unlink($path);
            }
        }

        $this->invalidUrl();
    }

    private function validateUrl(Uri $uri): void
    {
        if (! in_array(strtolower($uri->getScheme()), ['https', 'http'], true)
            || $uri->getHost() === ''
            || $uri->getUserInfo() !== ''
            || $uri->getFragment() !== '') {
            $this->invalidUrl();
        }
    }

    /** @param array<string, list<string>> $headers */
    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $values) {
            if (strtolower($key) === $name) {
                return $values[0] ?? null;
            }
        }

        return null;
    }

    private function invalidUrl(): never
    {
        throw ValidationException::withMessages([
            self::ERROR_FIELD => 'Вкажіть публічний URL зображення (HTTP або HTTPS), без облікових даних.',
        ]);
    }
}
