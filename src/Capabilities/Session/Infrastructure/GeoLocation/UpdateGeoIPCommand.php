<?php

declare(strict_types=1);

namespace App\Capabilities\Session\Infrastructure\GeoLocation;

use App\Capabilities\Session\Application\GeoLocationConfig;
use App\Platform\Http\Client\HttpClient;
use App\Platform\Http\Client\HttpMethod;
use App\Platform\Http\Client\HttpRequest;
use App\Platform\Http\Client\HttpRequestOptions;
use App\Platform\Http\Client\RetryPolicy;
use App\Platform\Logging\Logger;

/**
 * Updates the local IP2Location geolocation database.
 */
final readonly class UpdateGeoIPCommand
{
    public function __construct(
        private GeoLocationConfig $config,
        private HttpClient $httpClient,
        private Logger $logger,
    ) {}

    /**
     * Executes the database update flow.
     */
    public function execute(): void
    {
        $this->logger->info('Starting GeoIP database update');

        // Skip the update when the download token is not configured.
        if (empty($this->config->downloadToken)) {
            $this->logger->error('IP2Location download token is not set');

            return;
        }

        // Ensure the database directory exists before downloading the archive.
        $dbDir = \dirname($this->config->dbPath);
        if (!is_dir($dbDir) && !mkdir($dbDir, 0o755, true)) {
            $this->logger->error('Failed to create directory for GeoIP database', [
                'directory' => $dbDir,
            ]);

            return;
        }

        // Build the upstream download URL from the configured credentials.
        $downloadUrl = $this->config->getDownloadUrl();
        $this->logger->info('Downloading GeoIP database', [
            'url' => $downloadUrl,
            'database_code' => $this->config->databaseCode,
        ]);

        // Store the downloaded archive and extracted payload in temporary files first.
        $tempFile = $this->config->dbPath . '.tmp';
        $zipFile = $this->config->dbPath . '.zip';

        try {
            // Download the archive before extraction.
            $this->downloadFile($downloadUrl, $zipFile);
            $this->logger->info('Downloaded GeoIP database archive', [
                'size' => filesize($zipFile),
            ]);

            // Extract the database into a temporary file.
            $this->extractDatabase($zipFile, $dbDir, $tempFile);

            // Reject empty or missing extracted files before swapping them in place.
            if (!file_exists($tempFile) || filesize($tempFile) === 0) {
                throw new \RuntimeException('Extracted database file is empty or does not exist');
            }

            // Replace the active database after a successful extraction.
            if (file_exists($this->config->dbPath)) {
                unlink($this->config->dbPath);
            }
            rename($tempFile, $this->config->dbPath);

            $this->logger->info('GeoIP database updated successfully', [
                'path' => $this->config->dbPath,
                'size' => filesize($this->config->dbPath),
            ]);

            // Remove temporary artifacts after a successful update.
            if (file_exists($zipFile)) {
                unlink($zipFile);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to update GeoIP database', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Best-effort cleanup for partially downloaded or extracted artifacts.
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }
            if (file_exists($zipFile)) {
                unlink($zipFile);
            }
        }
    }

    /**
     * Downloads a file from the upstream endpoint.
     *
     * @param string $url Download URL
     * @param string $destination Target file path
     * @throws \RuntimeException When the download fails
     */
    private function downloadFile(string $url, string $destination): void
    {
        $response = $this->httpClient->send(
            new HttpRequest(
                method: HttpMethod::GET,
                uri: $url,
                upstream: 'ip2location-download',
                options: new HttpRequestOptions(
                    connectTimeoutSeconds: 10.0,
                    timeoutSeconds: 120.0,
                    followRedirects: true,
                    maxRedirects: 3,
                    idempotent: true,
                    userAgent: 'zerno-http-client/' . PHP_VERSION,
                    retryPolicy: new RetryPolicy(
                        maxAttempts: 3,
                        baseDelayMilliseconds: 250,
                        maxDelayMilliseconds: 2000,
                    ),
                ),
            ),
        );

        if (!$response->isSuccess()) {
            throw new \RuntimeException(
                'Failed to download file from ' . $url . ' with status ' . $response->statusCode,
            );
        }

        if (file_put_contents($destination, $response->body) === false) {
            throw new \RuntimeException('Failed to save downloaded file to ' . $destination);
        }
    }

    /**
     * Extracts the BIN database file from the downloaded ZIP archive.
     *
     * @param string $zipFile ZIP archive path
     * @param string $extractDir Extraction directory
     * @param string $targetFile Extracted BIN target path
     * @throws \RuntimeException When extraction fails
     */
    private function extractDatabase(string $zipFile, string $extractDir, string $targetFile): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new \RuntimeException('Failed to open ZIP archive: ' . $zipFile);
        }

        // Use an isolated extraction directory to avoid partial state leaks.
        $tempDir = $extractDir . '/temp_' . uniqid();
        if (!is_dir($tempDir) && !mkdir($tempDir, 0o755, true)) {
            $zip->close();

            throw new \RuntimeException('Failed to create temporary directory for extraction');
        }

        // Extract the archive into the isolated directory.
        if (!$zip->extractTo($tempDir)) {
            $zip->close();
            $this->removeDirectory($tempDir);

            throw new \RuntimeException('Failed to extract ZIP archive');
        }
        $zip->close();

        // Locate the first BIN file produced by the archive.
        $binFiles = glob($tempDir . '/*.BIN');
        if (empty($binFiles)) {
            $this->removeDirectory($tempDir);

            throw new \RuntimeException('No BIN files found in the extracted archive');
        }

        // Copy the extracted BIN file to the requested target path.
        if (!copy($binFiles[0], $targetFile)) {
            $this->removeDirectory($tempDir);

            throw new \RuntimeException('Failed to copy extracted BIN file to target location');
        }

        // Remove the temporary extraction directory once the copy succeeds.
        $this->removeDirectory($tempDir);
    }

    /**
     * Removes a directory recursively.
     *
     * @param string $dir Directory path
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $scanned = scandir($dir);
        if ($scanned !== false) {
            $files = array_diff($scanned, ['.', '..']);
            foreach ($files as $file) {
                $path = $dir . '/' . (string) $file;
                is_dir($path) ? $this->removeDirectory($path) : unlink($path);
            }
        }
        rmdir($dir);
    }
}
