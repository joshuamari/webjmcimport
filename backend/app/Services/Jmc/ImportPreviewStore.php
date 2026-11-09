<?php

namespace App\Services\Jmc;

use RuntimeException;

class ImportPreviewStore
{
    public function create(string $sourcePath, array $meta): string
    {
        $this->sweep();
        $token = bin2hex(random_bytes(16));
        $directory = $this->directory();
        if (! copy($sourcePath, $directory.DIRECTORY_SEPARATOR.$token.'.xlsx')) {
            throw new RuntimeException('The upload could not be stored.');
        }

        $meta['token'] = $token;
        $meta['status'] = 'ready';
        $meta['createdAt'] = time();
        $this->write($token, $meta);

        return $token;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $token): ?array
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        $path = $this->jsonPath($token);
        if (! is_file($path)) {
            return null;
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            return null;
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                return null;
            }
            $decoded = json_decode((string) stream_get_contents($handle), true);

            return is_array($decoded) ? $decoded : null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * A confirm request that died after marking itself committing no longer holds
     * the preview lock. Release that marker so a status check can see the real outcome.
     */
    public function releaseAbandoned(string $token): void
    {
        if (! preg_match('/^[a-f0-9]{32}$/', $token)) {
            return;
        }

        $path = $this->jsonPath($token);
        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }

            rewind($handle);
            $meta = json_decode((string) stream_get_contents($handle), true);
            if (! is_array($meta) || ($meta['status'] ?? '') !== 'committing') {
                return;
            }

            $committed = $meta['committed'] ?? null;
            if (is_array($committed) && array_key_exists('added', $committed)) {
                $meta['status'] = 'committed';
            } else {
                $meta['status'] = 'ready';
            }

            $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            rewind($handle);
            if (! ftruncate($handle, 0)) {
                return;
            }
            fwrite($handle, $encoded);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function filePath(string $token): ?string
    {
        $path = $this->directory().DIRECTORY_SEPARATOR.$token.'.xlsx';

        return is_file($path) ? $path : null;
    }

    /**
     * @param  callable(array<string, mixed>, callable): mixed  $callback
     */
    public function exclusive(string $token, callable $callback): mixed
    {
        $path = $this->jsonPath($token);
        if (! is_file($path)) {
            throw new ImportRejectedException('This preview has expired. Upload the file again.');
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new ImportRejectedException('This preview has expired. Upload the file again.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new ImportRejectedException('This preview is busy. Try again.');
            }

            rewind($handle);
            $meta = json_decode((string) stream_get_contents($handle), true);
            if (! is_array($meta)) {
                throw new ImportRejectedException('This preview has expired. Upload the file again.');
            }

            return $callback($meta, function (array $next) use ($handle): void {
                $encoded = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, $encoded);
                fflush($handle);
            });
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function write(string $token, array $meta): void
    {
        $encoded = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        file_put_contents($this->jsonPath($token), $encoded, LOCK_EX);
    }

    public function sweep(): void
    {
        $ttl = (int) config('jmc.preview_ttl_hours', 24) * 3600;
        $cutoff = time() - $ttl;
        foreach (glob($this->directory().DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            if (filemtime($file) !== false && filemtime($file) < $cutoff) {
                $token = basename($file, '.json');
                @unlink($file);
                @unlink($this->directory().DIRECTORY_SEPARATOR.$token.'.xlsx');
            }
        }
    }

    private function directory(): string
    {
        $dir = storage_path('app/private/jmc-previews');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('The upload could not be stored.');
        }

        return $dir;
    }

    private function jsonPath(string $token): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.$token.'.json';
    }
}
