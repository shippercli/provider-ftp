<?php

declare(strict_types=1);

namespace ShipperCli\ProviderFtp;

interface Transport
{
    /** @return array<string, 'file'|'dir'> */
    public function listing(string $path): array;

    public function mkdir(string $path): void;

    public function read(string $path): string;

    public function write(string $path, string $contents): void;

    public function rename(string $from, string $to): void;

    public function delete(string $path): void;

    public function rmdir(string $path): void;
}
