<?php

declare(strict_types=1);

namespace ShipperCli\ProviderFtp;

/** All server diagnostics are replaced with fixed messages to avoid credential leaks. */
final class CurlTransport implements Transport
{
    public function __construct(private readonly array $config) {}

    public function listing(string $path): array
    {
        $body = $this->request(rtrim($path, '/').'/', [CURLOPT_CUSTOMREQUEST => 'MLSD']);
        $entries = [];
        foreach (explode("\n", $body) as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }
            if (! preg_match('/^([^ ]+;) (.+)$/D', $line, $match)) {
                throw new \RuntimeException('FTP server returned an invalid MLSD directory listing.');
            }
            if (! preg_match('/(?:^|;)type=([^;]+);/i', $match[1], $type)) {
                throw new \RuntimeException('FTP directory entry has no type.');
            }
            $kind = strtolower($type[1]);
            if (in_array($kind, ['cdir', 'pdir'], true)) {
                continue;
            }
            $name = $match[2];
            if (preg_match('/[\x00-\x1f\x7f\/\\\\]/', $name) || in_array($name, ['.', '..'], true)
                || ! in_array($kind, ['file', 'dir'], true) || isset($entries[$name])) {
                throw new \RuntimeException('FTP listing contains an unsafe or unsupported entry (including symlinks).');
            }
            $entries[$name] = $kind;
        }

        return $entries;
    }

    public function mkdir(string $path): void
    {
        $this->command(['MKD '.$path]);
    }

    public function read(string $path): string
    {
        return $this->request($path);
    }

    public function write(string $path, string $contents): void
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Cannot allocate FTP upload stream.');
        }
        try {
            if (fwrite($stream, $contents) !== strlen($contents)) {
                throw new \RuntimeException('Cannot buffer FTP upload.');
            }
            rewind($stream);
            $this->request($path, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $stream, CURLOPT_INFILESIZE => strlen($contents)]);
        } finally {
            fclose($stream);
        }
    }

    public function rename(string $from, string $to): void
    {
        $this->command(['RNFR '.$from, 'RNTO '.$to]);
    }

    public function delete(string $path): void
    {
        $this->command(['DELE '.$path]);
    }

    public function rmdir(string $path): void
    {
        $this->command(['RMD '.$path]);
    }

    private function command(array $commands): void
    {
        foreach ($commands as $command) {
            if (preg_match('/[\x00-\x1f\x7f]/', $command)) {
                throw new \RuntimeException('Unsafe FTP command.');
            }
        }
        $this->request('/', [CURLOPT_NOBODY => true, CURLOPT_QUOTE => $commands]);
    }

    private function request(string $path, array $options = []): string
    {
        $curl = curl_init();
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        // /%2f addresses the FTP account root, rather than the login working directory.
        $url = 'ftp://'.$this->config['host'].':'.($this->config['port'] ?? 21).'/%2f'.ltrim($encoded, '/');
        $base = [
            CURLOPT_URL => $url,
            CURLOPT_USERNAME => $this->config['username'],
            CURLOPT_PASSWORD => $this->config['password'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->config['timeout'] ?? 30,
            CURLOPT_TIMEOUT => $this->config['timeout'] ?? 30,
            CURLOPT_PROTOCOLS => CURLPROTO_FTP,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_FTP_SKIP_PASV_IP => true,
            CURLOPT_USE_SSL => ($this->config['protocol'] ?? 'ftps') === 'ftps' ? CURLUSESSL_ALL : CURLUSESSL_NONE,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if (isset($this->config['ca_file'])) {
            $base[CURLOPT_CAINFO] = $this->config['ca_file'];
        }
        try {
            curl_setopt_array($curl, $options + $base);
            $result = curl_exec($curl);
            $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($result === false || $code >= 400) {
                throw new \RuntimeException('FTP operation failed (cURL '.curl_errno($curl).', FTP '.$code.').');
            }

            return is_string($result) ? $result : '';
        } finally {
            unset($curl);
        }
    }
}
