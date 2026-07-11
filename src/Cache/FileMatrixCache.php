<?php

declare(strict_types=1);

namespace SqrArt\QArt\Cache;

use SqrArt\QArt\Exception\QArtException;

final class FileMatrixCache implements MatrixCache
{
    public function __construct(private readonly string $dir)
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new QArtException("impossible de créer le répertoire de cache: {$this->dir}");
        }
    }

    public function get(string $key): ?array
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = @gzuncompress($raw);
        if ($data === false) {
            return null;
        }
        $cols = @unserialize($data, ['allowed_classes' => false]);

        return is_array($cols) ? $cols : null;
    }

    public function set(string $key, array $cols): void
    {
        $path = $this->path($key);
        $tmp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($tmp, gzcompress(serialize($cols), 6)) === false) {
            return; // cache best-effort : ne jamais faire échouer la génération
        }
        @rename($tmp, $path);
    }

    private function path(string $key): string
    {
        return $this->dir.'/'.preg_replace('/[^A-Za-z0-9._-]/', '_', $key).'.bin.gz';
    }
}
