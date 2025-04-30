<?php

namespace Api\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Aws\S3\S3Client;

class S3Sync
{
    private Filesystem $fs;
    private string $bucket;
    private array $syncPaths = ['files', 'schemas', 'history'];
    private string $fingerPrint;

    public function __construct(array $config)
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => $config['region'],
            'credentials' => [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ],
            'endpoint' => $config['endpoint'] ?? null,
            'use_path_style_endpoint' => true,
        ]);

        $this->bucket = $config['bucket'];
        $prefix = $config['prefix'] ?? '';

        $adapter = new AwsS3V3Adapter($client, $this->bucket, $prefix);
        $this->fs = new Filesystem($adapter);
        $this->fingerPrint = md5(json_encode($config));
    }

    public function syncFromBucket(string $workspaceRoot): void
    {
        foreach ($this->syncPaths as $dir) {
            $this->downloadDir("{$workspaceRoot}/{$dir}", $dir);
        }
    }

    public function uploadFile(string $localPath, string $relativePath): void
    {
        if (!file_exists($localPath)) {
            return;
        }

        $stream = fopen($localPath, 'r');
        $this->fs->writeStream($relativePath, $stream);
        fclose($stream);
    }

    public function deleteFile(string $relativePath): void
    {
        try {
            $this->fs->delete($relativePath);
        } catch (FilesystemException $e) {
            // Ignore if file does not exist remotely
        }
    }

    public function uploadSchema(array $schema, string $schemasPath): void
    {
        $filename = $schemasPath . '/' . $schema['name'] . '.json';
        $this->uploadFile($filename, 'schemas/' . basename($filename));
    }

    public function syncToBucket(string $workspaceRoot): void
    {
        foreach ($this->syncPaths as $dir) {
            $localDir = $workspaceRoot . '/' . $dir;
            foreach (glob($localDir . '/*') as $file) {
                $this->uploadFile($file, $dir . '/' . basename($file));
            }
        }
    }

    private function downloadDir(string $localPath, string $remotePrefix): void
    {
        if (!is_dir($localPath)) {
            mkdir($localPath, 0775, true);
        }

        foreach ($this->fs->listContents($remotePrefix, true) as $item) {
            if ($item->isFile()) {
                $path = $item->path();
                $stream = $this->fs->readStream($path);
                file_put_contents("{$localPath}/" . basename($path), stream_get_contents($stream));
                fclose($stream);
            }
        }
    }

    public function getConfigFingerprint(): string
    {
        return $this->fingerPrint;
    }
}
