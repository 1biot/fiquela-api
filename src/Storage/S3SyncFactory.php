<?php

namespace Api\Storage;

readonly class S3SyncFactory
{
    /**
     * @param array $config
     */
    public function __construct(private array $config)
    {
    }

    public function create(): ?S3Sync
    {
        if (!$this->config['enabled']) {
            return null;
        }

        $config = $this->config;
        unset($config['enabled']);
        return new S3Sync($config);
    }
}
