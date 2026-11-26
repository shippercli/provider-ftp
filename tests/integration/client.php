<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use ShipperCli\ProviderFtp\FtpProvider;

$config = json_decode(getenv('FTP_TEST_CONFIG'), true, flags: JSON_THROW_ON_ERROR);
$project = new class(getenv('FTP_TEST_SOURCE'))
{
    public function __construct(private string $path) {}

    public function name(): string
    {
        return 'integration';
    }

    public function path(): string
    {
        return $this->path;
    }
};
$profile = new class
{
    public function name(): string
    {
        return 'test';
    }

    public function get(string $key): mixed
    {
        return $key === 'ftp' ? ['remote_path' => '/site', 'local_path' => '.'] : null;
    }
};
$provider = new FtpProvider($config);
$action = $argv[1];
if ($action === 'failure') {
    if ($provider->apply($project, $profile)) {
        throw new RuntimeException('Expected connection failure.');
    }
    if (str_contains($provider->getLastError(), $config['password'])) {
        throw new RuntimeException('Password leaked.');
    }
    echo "Connection rejected without exposing credentials.\n";
    exit;
}
if ($provider->validate($project, $profile) !== []) {
    throw new RuntimeException('Invalid integration fixture.');
}
$success = match ($action) {
    'apply' => $provider->apply($project, $profile),
    'destroy' => $provider->destroy($project, $profile),
    default => throw new RuntimeException('Unknown integration action.'),
};
if (! $success) {
    throw new RuntimeException($provider->getLastError());
}
$state = $provider->status($project, $profile);
if ($state['state'] !== ($action === 'apply' ? 'deployed' : 'not_deployed')) {
    throw new RuntimeException('Unexpected deployment status.');
}
echo $action." passed.\n";
