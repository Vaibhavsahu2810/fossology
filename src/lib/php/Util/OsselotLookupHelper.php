<?php
/**
 * OsselotLookupHelper.php
 *
 * A utility class to interact with both the OSSelot REST API and the
 * Open-Source-Compliance GitHub repository for package analysis data.
 *
 * @package Fossology\Lib\Util
 */

namespace Fossology\Lib\Util;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

class OsselotLookupHelper
{
    /** @var string Base URL for the OSSelot API */
    private string $baseUrl;

    /** @var Client HTTP client instance */
    private Client $client;

    /** @var string Directory path for caching downloaded SPDX files */
    private string $cacheDir;

    /** @var int Time-to-live for cache in seconds (default: 24h) */
    private int $cacheTtl = 86400;

    /**
     * Constructor.
     * Initializes the HTTP client and cache directory.
     */
    public function __construct()
    {
        $this->baseUrl = 'https://rest.osselot.org/';
        $this->client = new Client([
            'timeout'         => 300,
            'connect_timeout' => 5,
        ]);

        $baseCache = $GLOBALS['SysConf']['DIRECTORIES']['cache'] ?? sys_get_temp_dir();
        $this->cacheDir = rtrim($baseCache, '/\\') . '/util/osselot';

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Fetches the list of versions for a given package by reading
     * the analysed-packages directory in the Open-Source-Compliance GitHub repo.
     *
     * @param string $pkgName Package identifier to look up (e.g., "FreeRTOS-Kernel").
     * @return array List of version strings; empty if none found or on error.
     */
    public function getVersions(string $pkgName): array
    {
        $apiUrl = "https://api.github.com/repos/Open-Source-Compliance/package-analysis/contents/analysed-packages/{$pkgName}";
        try {
            $response = $this->client->get($apiUrl, [
                'headers' => [
                    'Accept'     => 'application/vnd.github.v3+json',
                    'User-Agent' => 'Fossology-OsselotHelper',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $data = json_decode((string)$response->getBody(), true);
            if (!is_array($data)) {
                return [];
            }

            $versions = [];
            foreach ($data as $entry) {
                if ($entry['type'] === 'dir' && isset($entry['name'])) {
                    $name = $entry['name'];
                    if (strpos($name, 'version-') === 0) {
                        $version = substr($name, 8); 
                        if (!empty($version)) {
                            $versions[] = $version;
                        }
                    }
                }
            }

            sort($versions, SORT_NATURAL);
            return array_unique($versions);

        } catch (GuzzleException $e) {
            error_log('GitHub API error in getVersions(): ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Downloads (or retrieves from cache) the SPDX RDF/XML file for a given package/version.
     *
     * @param string $pkgName Package identifier.
     * @param string $version Version string.
     * @return string|null Path to the cached RDF file, or null on failure.
     */
    public function fetchSpdxFile(string $pkgName, string $version): ?string
    {
        $safeName  = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $pkgName);
        $safeVer   = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $version);
        $cacheFile = "{$this->cacheDir}/{$safeName}_{$safeVer}.rdf";

        // Check if cached file exists and is still valid
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $this->cacheTtl) {
            return $cacheFile;
        }

        try {
            // Construct the API URL
            $apiUrl = $this->baseUrl . 'xml/' . urlencode($pkgName) . '/' . urlencode($version);
            
            $response = $this->client->get($apiUrl, [
                'headers' => [
                    'Accept' => 'application/rdf+xml, application/xml, text/xml',
                    'User-Agent' => 'Fossology-OsselotHelper'
                ]
            ]);
            
            if ($response->getStatusCode() === 200) {
                $content = $response->getBody()->getContents();
                
                // Validate that we received XML content
                if (!empty($content) && $this->isValidXml($content)) {
                    // Ensure cache directory exists
                    if (!is_dir($this->cacheDir)) {
                        @mkdir($this->cacheDir, 0755, true);
                    }
                    
                    if (file_put_contents($cacheFile, $content) !== false) {
                        return $cacheFile;
                    }
                }
            }
            
            return null;

        } catch (GuzzleException $e) {
            error_log('OSSelot API error in fetchSpdxFile(): ' . $e->getMessage());
            return null;
        }
    }


    /**
     * Gets SPDX data for a specific package version as an array.
     *
     * @param string $pkgName Package identifier.
     * @param string $version Version string.
     * @return array|null SPDX data as associative array, or null on failure.
     */
    private function isValidXml(string $content): bool
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        
        $doc = simplexml_load_string($content);
        $errors = libxml_get_errors();
        
        libxml_use_internal_errors($previousUseInternalErrors);
        libxml_clear_errors();
        
        return $doc !== false && empty($errors);
    }

    /**
     * Clears the cache directory.
     *
     * @return bool True on success, false on failure.
     */
    public function clearCache(): bool
    {
        if (!is_dir($this->cacheDir)) {
            return true;
        }

        foreach (glob($this->cacheDir . '/*.rdf') as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        return true;
    }
}