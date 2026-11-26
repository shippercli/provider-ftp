<?php

declare(strict_types=1);

namespace ShipperCli\ProviderFtp\Tests;

use ShipperCli\ProviderFtp\Transport;

/** A strict test filesystem: missing directories and file/directory conflicts fail. */
final class MemoryTransport implements Transport
{
    /** @var array<string, string> */
    public array $files = [];

    /** @var array<string, true> */
    public array $directories = ['/' => true];

    /** @var list<array{string, string}> */
    public array $calls = [];

    public ?\Closure $before = null;

    public function listing(string $path): array
    {
        $this->call('listing', $path);
        if (! isset($this->directories[$path])) {
            throw new \RuntimeException('Remote directory unavailable.');
        }
        $result = [];
        foreach ($this->directories as $child => $_) {
            if ($child !== $path && dirname($child) === $path) {
                $result[basename($child)] = 'dir';
            }
        }
        foreach ($this->files as $child => $_) {
            if (dirname($child) === $path) {
                $result[basename($child)] = 'file';
            }
        }

        return $result;
    }

    public function mkdir(string $path): void
    {
        $this->call('mkdir', $path);
        $this->parent($path);
        if (isset($this->files[$path]) || isset($this->directories[$path])) {
            throw new \RuntimeException('Remote path already exists.');
        }
        $this->directories[$path] = true;
    }

    public function read(string $path): string
    {
        $this->call('read', $path);
        if (! array_key_exists($path, $this->files)) {
            throw new \RuntimeException('Remote file unavailable.');
        }

        return $this->files[$path];
    }

    public function write(string $path, string $contents): void
    {
        $this->call('write', $path);
        $this->parent($path);
        if (isset($this->directories[$path])) {
            throw new \RuntimeException('Remote destination is a directory.');
        }
        $this->files[$path] = $contents;
    }

    public function rename(string $from, string $to): void
    {
        $this->call('rename', $to);
        $this->parent($to);
        if (! array_key_exists($from, $this->files) || isset($this->directories[$to])) {
            throw new \RuntimeException('Remote rename failed.');
        }
        $this->files[$to] = $this->files[$from];
        unset($this->files[$from]);
    }

    public function delete(string $path): void
    {
        $this->call('delete', $path);
        if (! array_key_exists($path, $this->files)) {
            throw new \RuntimeException('Remote file unavailable.');
        }
        unset($this->files[$path]);
    }

    public function rmdir(string $path): void
    {
        $this->call('rmdir', $path);
        if ($path === '/' || $this->listing($path) !== []) {
            throw new \RuntimeException('Remote directory is not empty.');
        }
        unset($this->directories[$path]);
    }

    /** Arrange fixtures without adding calls to the provider operation log. */
    public function put(string $path, string $contents): void
    {
        $directory = dirname($path);
        while ($directory !== '/') {
            $this->directories[$directory] = true;
            $directory = dirname($directory);
        }
        $this->files[$path] = $contents;
    }

    private function parent(string $path): void
    {
        if (! isset($this->directories[dirname($path)])) {
            throw new \RuntimeException('Remote parent unavailable.');
        }
    }

    private function call(string $operation, string $path): void
    {
        $this->calls[] = [$operation, $path];
        if ($this->before !== null) {
            ($this->before)($operation, $path);
        }
    }
}
