<?php

class getiiif {

    private const PUBLISHED_BASE_URL = 'https://404mike.github.io/iiif-training';

    private array $links = [];
    private string $manifestDirectory = __DIR__ . '/manifests';
    private string $imageDirectory = __DIR__ . '/images';

    public function __construct()
    {
        $this->links = $this->readLinksFile();
        $this->createDirectories();
        $this->loopLinks();
        $this->loopImages();
        $this->publishManifests();
        $this->createIndex();
    }

    private function readLinksFile(): array
    {
        $path = __DIR__ . '/files';
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Unable to open links file: {$path}");
        }

        $links = [];

        try {
            while (($line = fgets($handle)) !== false) {
                $link = trim($line);

                if ($link !== '') {
                    $links[] = $link;
                }
            }

            if (!feof($handle)) {
                throw new RuntimeException("Unable to read links file: {$path}");
            }
        } finally {
            fclose($handle);
        }

        return $links;
    }

    private function createDirectories(): void
    {
        foreach ([$this->manifestDirectory, $this->imageDirectory] as $directory) {
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException("Unable to create directory: {$directory}");
            }
        }
    }

    private function loopLinks(): void
    {
        foreach ($this->links as $link) {
            $nid = $this->getItemId($link);
            $manifestURL = "https://www.peoplescollection.wales/iiif/manifest/{$nid}";
            $this->downloadManifest($manifestURL, $nid);
        }
    }

    private function getItemId(string $url): int
    {
        $path = parse_url($url, PHP_URL_PATH);
        $nid = is_string($path) ? basename(rtrim($path, '/')) : '';

        if ($nid === '' || !ctype_digit($nid)) {
            throw new RuntimeException("Unable to find a numeric item ID in URL: {$url}");
        }

        return (int) $nid;
    }

    private function downloadManifest(string $url, int $nid): void
    {
        $destination = $this->manifestDirectory . "/{$nid}.json";

        if (is_file($destination) && filesize($destination) > 0) {
            return;
        }

        $json = $this->request($url);
        json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (file_put_contents($destination, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to save manifest: {$destination}");
        }
    }

    private function loopImages(): void
    {
        foreach ($this->links as $link) {
            $nid = $this->getItemId($link);
            $manifestPath = $this->manifestDirectory . "/{$nid}.json";

            try {
                $manifest = $this->readManifest($manifestPath);
                $imageUrls = $this->findImageUrls($manifest);

                if ($imageUrls === []) {
                    throw new RuntimeException("No painting images found in {$manifestPath}");
                }

                foreach ($imageUrls as $imageUrl) {
                    try {
                        $this->downloadImage($imageUrl);
                    } catch (Throwable $exception) {
                        fwrite(STDERR, "Image failed for item {$nid}: {$exception->getMessage()}" . PHP_EOL);
                    }
                }
            } catch (Throwable $exception) {
                fwrite(STDERR, "Manifest failed for item {$nid}: {$exception->getMessage()}" . PHP_EOL);
            }
        }
    }

    private function readManifest(string $path): array
    {
        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Unable to read manifest: {$path}");
        }

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function findImageUrls(array $manifest): array
    {
        $urls = [];

        foreach ($manifest['items'] ?? [] as $canvas) {
            foreach ($canvas['items'] ?? [] as $annotationPage) {
                foreach ($annotationPage['items'] ?? [] as $annotation) {
                    $motivations = (array) ($annotation['motivation'] ?? []);

                    if (!in_array('painting', $motivations, true)) {
                        continue;
                    }

                    foreach ($this->findImagesInBody($annotation['body'] ?? []) as $url) {
                        $urls[$url] = $url;
                    }
                }
            }
        }

        return array_values($urls);
    }

    private function findImagesInBody(array $body): array
    {
        if (array_is_list($body)) {
            $urls = [];

            foreach ($body as $item) {
                if (is_array($item)) {
                    $urls = array_merge($urls, $this->findImagesInBody($item));
                }
            }

            return $urls;
        }

        if (($body['type'] ?? null) === 'Image' && isset($body['id']) && is_string($body['id'])) {
            return [$body['id']];
        }

        if (($body['type'] ?? null) === 'Choice' && isset($body['items']) && is_array($body['items'])) {
            return $this->findImagesInBody($body['items']);
        }

        return [];
    }

    private function publishManifests(): void
    {
        foreach ($this->links as $link) {
            $nid = $this->getItemId($link);
            $path = $this->manifestDirectory . "/{$nid}.json";
            $manifest = $this->readManifest($path);
            $sourceManifestId = $manifest['id'] ?? null;

            if (!is_string($sourceManifestId) || $sourceManifestId === '') {
                throw new RuntimeException("Manifest has no ID: {$path}");
            }

            $publishedManifestId = self::PUBLISHED_BASE_URL . "/manifests/{$nid}.json";
            $this->rewriteResourceUris($manifest, $sourceManifestId, $publishedManifestId);

            $json = json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );

            if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
                throw new RuntimeException("Unable to save published manifest: {$path}");
            }
        }
    }

    private function rewriteResourceUris(
        array &$resource,
        string $sourceManifestId,
        string $publishedManifestId,
        bool $isThumbnail = false
    ): void {
        if (($resource['type'] ?? null) === 'Image' && isset($resource['id']) && is_string($resource['id'])) {
            $resource['id'] = $this->publishedImageUrl($resource['id']);

            // The local file is the full image, not the 250px derivative from
            // the source manifest, so thumbnail dimensions no longer apply.
            if ($isThumbnail) {
                unset($resource['width'], $resource['height']);
            }
        } elseif (isset($resource['id']) && is_string($resource['id'])) {
            $resource['id'] = $this->replaceManifestBase(
                $resource['id'],
                $sourceManifestId,
                $publishedManifestId
            );
        }

        if (isset($resource['target']) && is_string($resource['target'])) {
            $resource['target'] = $this->replaceManifestBase(
                $resource['target'],
                $sourceManifestId,
                $publishedManifestId
            );
        }

        foreach ($resource as $key => &$value) {
            if (!is_array($value)) {
                continue;
            }

            $childIsThumbnail = $isThumbnail || $key === 'thumbnail';

            if (array_is_list($value)) {
                foreach ($value as &$item) {
                    if (is_array($item)) {
                        $this->rewriteResourceUris(
                            $item,
                            $sourceManifestId,
                            $publishedManifestId,
                            $childIsThumbnail
                        );
                    }
                }
                unset($item);
            } else {
                $this->rewriteResourceUris(
                    $value,
                    $sourceManifestId,
                    $publishedManifestId,
                    $childIsThumbnail
                );
            }
        }
        unset($value);
    }

    private function replaceManifestBase(
        string $uri,
        string $sourceManifestId,
        string $publishedManifestId
    ): string {
        if ($sourceManifestId === $publishedManifestId || !str_starts_with($uri, $sourceManifestId)) {
            return $uri;
        }

        return $publishedManifestId . substr($uri, strlen($sourceManifestId));
    }

    private function publishedImageUrl(string $sourceUrl): string
    {
        $path = parse_url($sourceUrl, PHP_URL_PATH);
        $filename = is_string($path) ? rawurldecode(basename($path)) : '';
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? '';

        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new RuntimeException("Unable to determine a published image URL for: {$sourceUrl}");
        }

        return self::PUBLISHED_BASE_URL . '/images/' . rawurlencode($filename);
    }

    private function createIndex(): void
    {
        $items = [];

        foreach ($this->links as $link) {
            $nid = $this->getItemId($link);
            $manifest = $this->readManifest($this->manifestDirectory . "/{$nid}.json");
            $label = $this->manifestLabel($manifest, (string) $nid);
            $href = "manifests/{$nid}.json";
            $items[] = '      <li><a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '">'
                . htmlspecialchars($label, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</a></li>';
        }

        $html = '<!doctype html>' . PHP_EOL
            . '<html lang="en">' . PHP_EOL
            . '<head>' . PHP_EOL
            . '  <meta charset="utf-8">' . PHP_EOL
            . '  <meta name="viewport" content="width=device-width, initial-scale=1">' . PHP_EOL
            . '  <title>IIIF Training Manifests</title>' . PHP_EOL
            . '</head>' . PHP_EOL
            . '<body>' . PHP_EOL
            . '  <main>' . PHP_EOL
            . '    <h1>IIIF Training Manifests</h1>' . PHP_EOL
            . '    <ul>' . PHP_EOL
            . implode(PHP_EOL, $items) . PHP_EOL
            . '    </ul>' . PHP_EOL
            . '  </main>' . PHP_EOL
            . '</body>' . PHP_EOL
            . '</html>' . PHP_EOL;

        $path = __DIR__ . '/index.html';

        if (file_put_contents($path, $html, LOCK_EX) === false) {
            throw new RuntimeException("Unable to save index: {$path}");
        }
    }

    private function manifestLabel(array $manifest, string $fallback): string
    {
        $labels = $manifest['label'] ?? [];

        if (is_array($labels)) {
            foreach (['en', 'none', 'cy'] as $language) {
                if (isset($labels[$language][0]) && is_string($labels[$language][0])) {
                    return $labels[$language][0];
                }
            }

            foreach ($labels as $values) {
                if (is_array($values) && isset($values[0]) && is_string($values[0])) {
                    return $values[0];
                }
            }
        }

        return "Manifest {$fallback}";
    }

    private function downloadImage(string $url): void
    {
        $path = parse_url($url, PHP_URL_PATH);
        $filename = is_string($path) ? rawurldecode(basename($path)) : '';
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?? '';

        if ($filename === '' || $filename === '.' || $filename === '..') {
            throw new RuntimeException("Unable to determine a filename for image: {$url}");
        }

        $destination = $this->imageDirectory . "/{$filename}";

        if (is_file($destination) && filesize($destination) > 0) {
            echo "Skipped {$filename} (already downloaded)" . PHP_EOL;
            return;
        }

        $temporaryPath = $destination . '.part';
        $handle = fopen($temporaryPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to create image file: {$temporaryPath}");
        }

        $curl = curl_init($url);

        if ($curl === false) {
            fclose($handle);
            @unlink($temporaryPath);
            throw new RuntimeException("Unable to initialise download: {$url}");
        }

        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_USERAGENT => 'IIIF training image downloader/1.0',
        ]);

        $downloaded = curl_exec($curl);
        $error = curl_error($curl);
        $contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);
        fclose($handle);

        if ($downloaded === false || !is_string($contentType) || !str_starts_with($contentType, 'image/')) {
            @unlink($temporaryPath);
            $reason = $error !== '' ? $error : "unexpected content type {$contentType}";
            throw new RuntimeException("Unable to download {$url}: {$reason}");
        }

        if (!rename($temporaryPath, $destination)) {
            @unlink($temporaryPath);
            throw new RuntimeException("Unable to finish image file: {$destination}");
        }

        echo "Downloaded {$filename}" . PHP_EOL;
    }

    private function request(string $url): string
    {
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException("Unable to initialise request: {$url}");
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_FAILONERROR => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'IIIF training manifest downloader/1.0',
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if (!is_string($response)) {
            throw new RuntimeException("Unable to download {$url}: {$error}");
        }

        return $response;
    }
}

new getiiif();
