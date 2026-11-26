<?php

declare(strict_types=1);

namespace ShipperCli\ProviderFtp;

use ShipperCli\Contracts\CapabilityManifest;
use ShipperCli\Contracts\DeploymentProviderInterface;
use ShipperCli\Contracts\DeploymentStatusProviderInterface;
use ShipperCli\Contracts\ProviderCapabilitiesInterface;

final class FtpProvider implements DeploymentProviderInterface, DeploymentStatusProviderInterface, ProviderCapabilitiesInterface
{
    private const MANIFEST = '.shipper-ftp.json';

    private string $lastError = '';

    private ?Transport $transport;

    public function __construct(private readonly array $config = [], ?Transport $transport = null)
    {
        $this->transport = $transport;
    }

    public function getName(): string
    {
        return 'ftp';
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function capabilities(): array
    {
        $result = array_fill_keys(CapabilityManifest::CAPABILITIES, ['state' => 'unsupported']);
        $result['app_deploy'] = ['state' => 'supported', 'notes' => 'Upload prebuilt files to a dedicated FTP directory; no remote build or shell commands.'];
        $result['profiles'] = ['state' => 'supported'];
        $result['observability'] = ['state' => 'partial', 'notes' => 'Remote deployment manifest status only; no runtime health, metrics or server logs.'];

        return $result;
    }

    public function validate(object $project, object $profile): array
    {
        try {
            $this->settings($project, $profile);
            $this->source($project, $profile);

            return [];
        } catch (\Throwable $e) {
            return [$e->getMessage()];
        }
    }

    public function plan(object $project, object $profile): array
    {
        $settings = $this->settings($project, $profile);
        $files = $this->source($project, $profile);

        return [
            'provider' => 'ftp', 'project' => $project->name(), 'profile' => $profile->name(),
            'path' => $settings['remote_path'], 'protocol' => $this->config['protocol'] ?? 'ftps',
            'file_count' => count($files),
            'actions' => ['Require an empty or matching Shipper-managed remote directory', 'Upload '.count($files).' files via temporary files and rename', 'Remove stale files tracked by the previous deployment', 'Save the remote deployment manifest'],
            'note' => 'Local plan only. Remote permissions and ownership are checked during apply. Deployments are not atomic across multiple files.',
        ];
    }

    public function apply(object $project, object $profile): bool
    {
        return $this->operation(function () use ($project, $profile): void {
            $settings = $this->settings($project, $profile);
            $source = $this->source($project, $profile);
            $root = $settings['remote_path'];
            $this->locate($root, true);
            $old = $this->manifest($root, $project, $profile);
            if ($old === null && $this->remote()->listing($root) !== []) {
                throw new \RuntimeException('First FTP deployment requires an empty dedicated remote directory.');
            }
            $previous = $old['files'] ?? [];
            $files = array_map('strval', array_keys($source));
            // Check every destination before claiming ownership or changing application files.
            foreach ($files as $file) {
                $type = $this->fileType($root, $file);
                if ($type !== null && ($type !== 'file' || ! in_array($file, $previous, true))) {
                    throw new \RuntimeException('FTP deployment would overwrite an untracked file or directory.');
                }
            }
            $journal = ['version' => 1, 'owner' => $this->owner($project, $profile, $root), 'state' => 'deploying',
                'files' => array_values(array_unique([...$previous, ...$files])), 'applied_at' => null];
            $this->save($root, $journal);
            foreach ($source as $file => $local) {
                $file = (string) $file;
                $parent = dirname($root.'/'.$file);
                $this->locate($parent, true);
                $contents = file_get_contents($local);
                if ($contents === false) {
                    throw new \RuntimeException('Cannot read an FTP source file.');
                }
                $temporary = $this->temporary($root, $file);
                $this->remote()->write($temporary, $contents);
                $this->remote()->rename($temporary, $root.'/'.$file);
            }
            foreach (array_diff($previous, $files) as $file) {
                $this->removeFile($root, $file);
                $this->removeFile($root, basename($this->temporary($root, $file)));
            }
            $journal['files'] = $files;
            $journal['state'] = 'deployed';
            $journal['applied_at'] = gmdate('c');
            $this->save($root, $journal);
        });
    }

    public function destroy(object $project, object $profile): bool
    {
        return $this->operation(function () use ($project, $profile): void {
            $root = $this->settings($project, $profile)['remote_path'];
            if (! $this->locate($root, false)) {
                return;
            }
            $manifest = $this->manifest($root, $project, $profile);
            if ($manifest === null) {
                return;
            }
            foreach ($manifest['files'] as $file) {
                $this->removeFile($root, $file);
                $this->removeFile($root, basename($this->temporary($root, $file)));
            }
            $this->removeFile($root, '.shipper-ftp-state.tmp');
            if (isset($this->remote()->listing($root)[self::MANIFEST])) {
                $this->remote()->delete($root.'/'.self::MANIFEST);
            }
            // Keep the deployment directory and unrelated files. No recursive directory deletion.
        });
    }

    public function status(object $project, object $profile): array
    {
        $root = $this->settings($project, $profile)['remote_path'];
        $manifest = $this->locate($root, false) ? $this->manifest($root, $project, $profile) : null;

        return ['provider' => 'ftp', 'project' => $project->name(), 'profile' => $profile->name(),
            'state' => $manifest['state'] ?? 'not_deployed', 'deploy_path' => $root,
            'file_count' => count($manifest['files'] ?? []), 'applied_at' => $manifest['applied_at'] ?? null,
            'note' => 'Saved deployment state, not a runtime health check or a remote file integrity check.'];
    }

    private function operation(callable $callback): bool
    {
        $this->lastError = '';
        try {
            $callback();

            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
    }

    private function settings(object $project, object $profile): array
    {
        $allowed = ['host', 'port', 'username', 'password', 'protocol', 'timeout', 'ca_file'];
        if (array_diff(array_keys($this->config), $allowed)) {
            throw new \InvalidArgumentException('Unknown FTP provider option.');
        }
        foreach (['host', 'username', 'password'] as $key) {
            $value = $this->config[$key] ?? null;
            if (! is_string($value) || $value === '' || preg_match('/[\x00-\x1f\x7f]|\$\{/', $value)) {
                throw new \InvalidArgumentException('FTP '.$key.' is required and must not contain control characters or unresolved variables.');
            }
        }
        $host = $this->config['host'];
        if (strlen($host) > 253 || ! preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D', $host)) {
            throw new \InvalidArgumentException('FTP host must be a hostname or IPv4 address without a URL or port.');
        }
        if (! in_array($this->config['protocol'] ?? 'ftps', ['ftp', 'ftps'], true)) {
            throw new \InvalidArgumentException('FTP protocol must be ftp or ftps (explicit TLS).');
        }
        foreach (['port' => [1, 65535, 21], 'timeout' => [1, 3600, 30]] as $key => [$min, $max, $default]) {
            $value = $this->config[$key] ?? $default;
            if (! is_int($value) || $value < $min || $value > $max) {
                throw new \InvalidArgumentException('Invalid FTP '.$key.'.');
            }
        }
        if (isset($this->config['ca_file']) && (! is_string($this->config['ca_file']) || ! is_file($this->config['ca_file']) || ! is_readable($this->config['ca_file']))) {
            throw new \InvalidArgumentException('FTP ca_file must be a readable certificate bundle.');
        }
        $settings = $profile->get('ftp') ?? [];
        if (! is_array($settings) || array_diff(array_keys($settings), ['remote_path', 'local_path', 'exclude'])) {
            throw new \InvalidArgumentException('Invalid FTP profile options.');
        }
        $root = $settings['remote_path'] ?? null;
        if (! is_string($root) || ! str_starts_with($root, '/') || ! $this->safePath(substr($root, 1))) {
            throw new \InvalidArgumentException('FTP remote_path must be an absolute non-root path without traversal or control characters.');
        }
        foreach (['databases', 'ssl', 'environment', 'deploy_script', 'queues', 'cron', 'daemons', 'network_rules', 'redirects', 'php_version', 'nginx_config'] as $section) {
            if (method_exists($project, 'hasSection') && $project->hasSection($section)) {
                throw new \InvalidArgumentException('FTP cannot manage configured '.$section.'. Build and configure the artifact before uploading.');
            }
        }
        foreach (['runtime', 'server', 'environment', 'deploy_script', 'aliases', 'ssl'] as $section) {
            if ($profile->get($section) !== null) {
                throw new \InvalidArgumentException('FTP cannot manage profile '.$section.'.');
            }
        }
        foreach (['databases', 'queues', 'cron', 'daemons', 'networkRules', 'redirects', 'deployScript', 'phpVersion', 'nginxConfig'] as $method) {
            if (method_exists($project, $method) && ! in_array($project->{$method}(), [[], '', null], true)) {
                throw new \InvalidArgumentException('FTP cannot manage configured '.$method.'.');
            }
        }
        if (method_exists($project, 'ssl') && ($project->ssl()->enabled()
            || (method_exists($project->ssl(), 'forceHttps') && $project->ssl()->forceHttps()))) {
            throw new \InvalidArgumentException('FTP cannot provision website SSL.');
        }
        if (method_exists($project, 'environment') && ! $project->environment()->isEmpty()) {
            throw new \InvalidArgumentException('FTP cannot manage environment variables.');
        }
        if (method_exists($profile, 'server') && $profile->server() !== null) {
            throw new \InvalidArgumentException('FTP cannot manage servers.');
        }
        if (isset($settings['local_path']) && (! is_string($settings['local_path']) || ($settings['local_path'] !== '.' && ! $this->safePath($settings['local_path'])))) {
            throw new \InvalidArgumentException('FTP local_path must be relative without traversal.');
        }
        $exclude = $settings['exclude'] ?? [];
        if (! is_array($exclude) || ! array_is_list($exclude)) {
            throw new \InvalidArgumentException('FTP exclude must be a list of relative paths.');
        }
        foreach ($exclude as $item) {
            if (! is_string($item) || ! $this->safePath($item)) {
                throw new \InvalidArgumentException('FTP exclusions must be relative paths without traversal.');
            }
        }

        return $settings + ['local_path' => '.', 'exclude' => []];
    }

    /** @return array<string, string> relative remote name to local filename */
    private function source(object $project, object $profile): array
    {
        $settings = $this->settings($project, $profile);
        if (! is_string($project->path()) || trim($project->path()) === '') {
            throw new \InvalidArgumentException('FTP project path must be a nonempty local directory path.');
        }
        $base = realpath($project->path());
        $path = $base === false ? false : realpath($base.'/'.$settings['local_path']);
        if ($base === false || $path === false || ! is_dir($path) || ($path !== $base && ! str_starts_with($path, $base.'/'))) {
            throw new \InvalidArgumentException('FTP source must be an existing directory inside the project path.');
        }
        $files = [];
        $walk = function (string $directory, string $prefix = '') use (&$walk, &$files, $settings): void {
            foreach (new \DirectoryIterator($directory) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                $name = $entry->getFilename();
                $relative = $prefix.$name;
                if (in_array($name, ['.git', '.hg', '.svn', '.github', '.phpunit.cache', 'node_modules', 'auth.json', 'shipper.yml', 'shipper.yaml'], true)
                    || str_starts_with($name, '.env') || str_starts_with($name, '.shipper-ftp')) {
                    continue;
                }
                foreach ($settings['exclude'] as $exclude) {
                    if ($relative === $exclude || str_starts_with($relative, $exclude.'/')) {
                        continue 2;
                    }
                }
                if (! $this->safePath($relative) || $entry->isLink()) {
                    throw new \RuntimeException('FTP source contains an unsafe path or symlink.');
                }
                if ($entry->isDir()) {
                    $walk($entry->getPathname(), $relative.'/');
                } elseif ($entry->isFile() && $entry->isReadable()) {
                    $files[$relative] = $entry->getPathname();
                } else {
                    throw new \RuntimeException('FTP source contains an unreadable or unsupported file.');
                }
            }
        };
        $walk($path);
        if ($files === []) {
            throw new \RuntimeException('FTP source contains no deployable files.');
        }
        ksort($files);

        return $files;
    }

    private function safePath(string $path): bool
    {
        if ($path === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $path)) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function remote(): Transport
    {
        return $this->transport ??= new CurlTransport($this->config);
    }

    /** Walk every parent to reject symlinks and distinguish absence from denied access. */
    private function locate(string $path, bool $create): bool
    {
        $parent = '/';
        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }
            $entries = $this->remote()->listing($parent);
            $child = rtrim($parent, '/').'/'.$segment;
            if (! isset($entries[$segment])) {
                if (! $create) {
                    return false;
                }
                $this->remote()->mkdir($child);
            } elseif ($entries[$segment] !== 'dir') {
                throw new \RuntimeException('FTP deployment parent is not a directory.');
            }
            $parent = $child;
        }

        return true;
    }

    private function fileType(string $root, string $file): ?string
    {
        $parent = dirname($root.'/'.$file);
        if (! $this->locate($parent, false)) {
            return null;
        }

        return $this->remote()->listing($parent)[basename($file)] ?? null;
    }

    private function owner(object $project, object $profile, string $root): string
    {
        return hash('sha256', json_encode([$project->name(), $profile->name(), $root], JSON_THROW_ON_ERROR));
    }

    private function manifest(string $root, object $project, object $profile): ?array
    {
        $entries = $this->remote()->listing($root);
        $name = self::MANIFEST;
        // Recover the first journal if its atomic rename was interrupted before any app upload.
        if (! isset($entries[$name]) && $entries === ['.shipper-ftp-state.tmp' => 'file']) {
            $name = '.shipper-ftp-state.tmp';
        }
        $type = $entries[$name] ?? null;
        if ($type === null) {
            return null;
        }
        if ($type !== 'file') {
            throw new \RuntimeException('Invalid FTP deployment manifest.');
        }
        try {
            $data = json_decode($this->remote()->read($root.'/'.$name), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Invalid FTP deployment manifest JSON.');
        }
        if (! is_array($data) || ($data['version'] ?? null) !== 1 || ($data['owner'] ?? null) !== $this->owner($project, $profile, $root)
            || ! in_array($data['state'] ?? null, ['deployed', 'deploying'], true)
            || ! is_array($data['files'] ?? null) || ! array_is_list($data['files'])) {
            throw new \RuntimeException('FTP manifest is invalid or belongs to another project/profile.');
        }
        foreach ($data['files'] as $file) {
            if (! is_string($file) || ! $this->safePath($file)) {
                throw new \RuntimeException('Unsafe FTP manifest file path.');
            }
            foreach (explode('/', $file) as $segment) {
                if (str_starts_with($segment, '.shipper-ftp')) {
                    throw new \RuntimeException('Reserved FTP manifest file path.');
                }
            }
        }

        return $data;
    }

    private function save(string $root, array $data): void
    {
        $this->remote()->write($root.'/.shipper-ftp-state.tmp', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->remote()->rename($root.'/.shipper-ftp-state.tmp', $root.'/'.self::MANIFEST);
    }

    private function temporary(string $root, string $file): string
    {
        return $root.'/.shipper-ftp-tmp-'.hash('sha256', $file);
    }

    private function removeFile(string $root, string $file): void
    {
        $type = $this->fileType($root, $file);
        if ($type === null) {
            return;
        }
        if ($type !== 'file') {
            throw new \RuntimeException('Refusing FTP cleanup of a directory in place of a tracked file.');
        }
        $this->remote()->delete($root.'/'.$file);
    }
}
