<?php

namespace Api\Utils;

namespace Api\Utils;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class Downloader
{
    private Client $client;

    public function __construct(array $defaultOptions = [])
    {
        $this->client = new Client(array_merge([
            'timeout' => 10,
            'verify' => false,
        ], $defaultOptions));
    }

    public function downloadToFile(string $url, string $targetPath, array $options = []): \SplFileInfo
    {
        try {
            $response = $this->client->request('GET', $url, $options);
            file_put_contents($targetPath, $response->getBody()->getContents());
            $targetPath = realpath($targetPath) ?: null;
            if ($targetPath === null) {
                throw new \InvalidArgumentException("Invalid target path: $targetPath");
            }
            return new \SplFileInfo(realpath($targetPath));
        } catch (RequestException $e) {
            throw new \RuntimeException("Download failed: " . $e->getMessage(), 0, $e);
        } catch (GuzzleException $e) {
            throw new \RuntimeException("Download failed: " . $e->getMessage(), 0, $e);
        }
    }

    public function download(string $url, array $options = []): string
    {
        try {
            $response = $this->client->request('GET', $url, $options);
            return $response->getBody()->getContents();
        } catch (RequestException $e) {
            throw new \RuntimeException("Download failed: " . $e->getMessage(), 0, $e);
        }
    }
}
