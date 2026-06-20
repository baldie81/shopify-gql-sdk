<?php

declare(strict_types=1);

namespace Baldie81\ShopifyGraphQL\Resource;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Baldie81\ShopifyGraphQL\Exception\UploadException;
use Baldie81\ShopifyGraphQL\GraphQL\Client;

/**
 * Files / staged uploads.
 *
 * Local files (e.g. an FTP drop) and bulk-mutation variable files have no URL
 * Shopify can fetch, so they must be pushed to a staged upload target first.
 * {@see upload()} / {@see uploadMany()} handle image files and return
 * `resourceUrl`s usable as media `originalSource`s; {@see stageJsonl()} uploads a
 * JSONL of bulk-mutation variables and returns its `stagedUploadPath`.
 * {@see update()} wraps `fileUpdate`, which replaces an existing file's bytes in
 * place (the "REPLACE" that avoids duplicate files).
 *
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/stagedUploadsCreate Shopify Admin GraphQL: stagedUploadsCreate
 * @link https://shopify.dev/docs/api/admin-graphql/latest/mutations/fileUpdate Shopify Admin GraphQL: fileUpdate
 */
final class Files extends AbstractResource
{
    private const MIME = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'gif'  => 'image/gif',
        'svg'  => 'image/svg+xml',
    ];

    private readonly ClientInterface $uploader;

    public function __construct(Client $gql, ?ClientInterface $uploader = null)
    {
        parent::__construct($gql);
        // A plain client (no Shopify auth headers) for POSTing bytes to the
        // external staged-upload target.
        $this->uploader = $uploader ?? new GuzzleClient(['timeout' => 60.0]);
    }

    /**
     * Upload one local image file and return its `resourceUrl`.
     *
     * @throws UploadException
     */
    public function upload(string $path, ?string $filename = null, ?string $mimeType = null): string
    {
        if (!is_file($path)) {
            throw new UploadException("File not found: {$path}");
        }

        $filename ??= basename($path);
        $mimeType ??= self::guessMime($filename);

        $target = $this->stage($filename, $mimeType, 'IMAGE');
        $this->pushBody($target, fopen($path, 'r'), $filename, $mimeType);

        return (string) $target['resourceUrl'];
    }

    /**
     * Upload many local image files with a single `stagedUploadsCreate` (the bytes
     * are still pushed one request each — storage backends don't batch raw bytes).
     * Returns the `resourceUrl`s aligned to the input order.
     *
     * @param list<string> $paths
     * @return list<string>
     * @throws UploadException
     */
    public function uploadMany(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        $inputs = [];
        $meta   = [];
        foreach (array_values($paths) as $path) {
            if (!is_file($path)) {
                throw new UploadException("File not found: {$path}");
            }
            $filename = basename($path);
            $mimeType = self::guessMime($filename);
            $inputs[] = ['filename' => $filename, 'mimeType' => $mimeType, 'httpMethod' => 'POST', 'resource' => 'IMAGE'];
            $meta[]   = [$path, $filename, $mimeType];
        }

        $targets = $this->stageMany($inputs);

        $urls = [];
        foreach ($meta as $i => [$path, $filename, $mimeType]) {
            $this->pushBody($targets[$i], fopen($path, 'r'), $filename, $mimeType);
            $urls[] = (string) $targets[$i]['resourceUrl'];
        }

        return $urls;
    }

    /**
     * Upload a JSONL document of bulk-mutation variables and return its
     * `stagedUploadPath` — the `key` parameter, which is what
     * `bulkOperationRunMutation` expects (NOT the resourceUrl).
     *
     * @throws UploadException
     */
    public function stageJsonl(string $jsonl, string $filename = 'bulk_op_vars.jsonl'): string
    {
        $target = $this->stage($filename, 'text/jsonl', 'BULK_MUTATION_VARIABLES', (string) strlen($jsonl));
        $this->pushBody($target, $jsonl, $filename, 'text/jsonl');

        $key = self::parameter($target, 'key');
        if ($key === null) {
            throw new UploadException('Staged target is missing the "key" parameter needed for stagedUploadPath.');
        }

        return $key;
    }

    /**
     * Raw `stagedUploadsCreate`.
     *
     * @param list<array<string,mixed>> $inputs
     * @return array{stagedTargets:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function createStagedUploads(array $inputs): array
    {
        $mutation = 'mutation ($input: [StagedUploadInput!]!) {'
            . ' stagedUploadsCreate(input: $input) {'
            . ' stagedTargets { url resourceUrl parameters { name value } } userErrors { field message } } }';

        return $this->gql->execute($mutation, ['input' => array_values($inputs)])['stagedUploadsCreate']
            ?? ['stagedTargets' => [], 'userErrors' => []];
    }

    /**
     * `fileUpdate` — replace/patch existing files (e.g. swap an image's bytes via
     * `originalSource` while keeping its id and URL).
     *
     * @param list<array<string,mixed>> $files each a FileUpdateInput (needs `id`)
     * @return array{files:list<array<string,mixed>>,userErrors:list<array<string,mixed>>}
     */
    public function update(array $files): array
    {
        $mutation = 'mutation ($files: [FileUpdateInput!]!) {'
            . ' fileUpdate(files: $files) {'
            . ' files { id alt } userErrors { field message code } } }';

        return $this->gql->execute($mutation, ['files' => $this->payloads($files)])['fileUpdate']
            ?? ['files' => [], 'userErrors' => []];
    }

    /**
     * @return array<string,mixed> a single staged target
     * @throws UploadException
     */
    private function stage(string $filename, string $mimeType, string $resource, ?string $fileSize = null): array
    {
        $input = ['filename' => $filename, 'mimeType' => $mimeType, 'httpMethod' => 'POST', 'resource' => $resource];
        if ($fileSize !== null) {
            $input['fileSize'] = $fileSize;
        }

        return $this->stageMany([$input])[0];
    }

    /**
     * @param list<array<string,mixed>> $inputs
     * @return list<array<string,mixed>> staged targets, aligned to $inputs
     * @throws UploadException
     */
    private function stageMany(array $inputs): array
    {
        $created = $this->createStagedUploads($inputs);

        if (($created['userErrors'] ?? []) !== []) {
            throw new UploadException('stagedUploadsCreate failed: ' . json_encode($created['userErrors']));
        }

        $targets = $created['stagedTargets'] ?? [];
        if (count($targets) !== count($inputs)) {
            throw new UploadException('stagedUploadsCreate returned an unexpected number of targets.');
        }

        return $targets;
    }

    /**
     * @param array<string,mixed>  $target a staged target (url + parameters)
     * @param resource|string      $body
     * @throws UploadException
     */
    private function pushBody(array $target, $body, string $filename, string $mimeType): void
    {
        if (!isset($target['url'])) {
            throw new UploadException("Staged target for {$filename} has no upload URL.");
        }

        $multipart = [];
        foreach ($target['parameters'] ?? [] as $parameter) {
            $multipart[] = ['name' => (string) $parameter['name'], 'contents' => (string) $parameter['value']];
        }
        // The file field must come last for the storage backend (e.g. GCS).
        $multipart[] = [
            'name'     => 'file',
            'contents' => $body,
            'filename' => $filename,
            'headers'  => ['Content-Type' => $mimeType],
        ];

        try {
            $this->uploader->request('POST', (string) $target['url'], ['multipart' => $multipart]);
        } catch (\Throwable $e) {
            throw new UploadException("Pushing bytes for {$filename} failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string,mixed> $target
     */
    private static function parameter(array $target, string $name): ?string
    {
        foreach ($target['parameters'] ?? [] as $parameter) {
            if (($parameter['name'] ?? null) === $name) {
                return (string) $parameter['value'];
            }
        }

        return null;
    }

    private static function guessMime(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return self::MIME[$ext] ?? 'application/octet-stream';
    }
}
