<?php

declare(strict_types=1);

namespace ShipperCli\ProviderFtp\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShipperCli\Contracts\CapabilityManifest;
use ShipperCli\ProviderFtp\FtpPlugin;
use ShipperCli\ProviderFtp\FtpProvider;

final class FtpProviderTest extends TestCase
{
    private string $directory;

    private MemoryTransport $remote;

    private FtpProvider $provider;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/shipper-ftp-unit-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->local('index.html', 'release one');
        $this->remote = new MemoryTransport;
        $this->provider = new FtpProvider($this->config(), $this->remote);
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($this->directory);
    }

    public function test_deploy_repeat_update_and_destroy_preserve_unrelated_files(): void
    {
        $this->local('assets/site.css', 'body {}');
        $this->local('stale.txt', 'old');
        $this->assertApplied();
        self::assertSame('release one', $this->remote->files['/sites/demo/index.html']);
        self::assertSame('body {}', $this->remote->files['/sites/demo/assets/site.css']);
        $manifest = $this->manifest();
        self::assertSame(['assets/site.css', 'index.html', 'stale.txt'], $manifest['files']);
        self::assertSame('deployed', $manifest['state']);
        self::assertNotEmpty($manifest['applied_at']);
        $this->assertApplied();

        $this->remote->put('/sites/demo/customer-upload.txt', 'keep me');
        $this->remote->put('/sites/neighbour/index.html', 'another site');
        $this->local('index.html', 'release two');
        unlink($this->directory.'/stale.txt');
        $this->assertApplied();
        self::assertSame('release two', $this->remote->files['/sites/demo/index.html']);
        self::assertArrayNotHasKey('/sites/demo/stale.txt', $this->remote->files);
        self::assertSame('keep me', $this->remote->files['/sites/demo/customer-upload.txt']);
        self::assertTrue($this->provider->destroy($this->project(), $this->profile()), $this->provider->getLastError());
        self::assertSame([
            '/sites/demo/customer-upload.txt' => 'keep me',
            '/sites/neighbour/index.html' => 'another site',
        ], $this->remote->files);
        self::assertSame('not_deployed', $this->provider->status($this->project(), $this->profile())['state']);
        self::assertTrue($this->provider->destroy($this->project(), $this->profile()));
    }

    public function test_initial_deployment_refuses_nonempty_directory_without_modifying_it(): void
    {
        $this->remote->put('/sites/demo/index.html', 'customer site');
        $before = $this->remote->files;
        self::assertFalse($this->provider->apply($this->project(), $this->profile()));
        self::assertStringContainsString('empty dedicated', $this->provider->getLastError());
        self::assertSame($before, $this->remote->files);
    }

    public function test_other_project_cannot_apply_destroy_or_read_owned_deployment(): void
    {
        $this->assertApplied();
        $before = $this->remote->files;
        $other = $this->project('another-project');
        self::assertFalse($this->provider->apply($other, $this->profile()));
        self::assertFalse($this->provider->destroy($other, $this->profile()));
        self::assertSame($before, $this->remote->files);
        $this->expectException(\RuntimeException::class);
        $this->provider->status($other, $this->profile());
    }

    public function test_another_profile_cannot_claim_the_same_directory(): void
    {
        $this->assertApplied();
        self::assertFalse($this->provider->apply($this->project(), $this->profile([], 'staging')));
        self::assertStringContainsString('another project/profile', $this->provider->getLastError());
    }

    public function test_new_local_file_cannot_overwrite_untracked_remote_file(): void
    {
        $this->assertApplied();
        $this->remote->put('/sites/demo/new.txt', 'customer upload');
        $this->local('new.txt', 'deployment file');
        $before = $this->remote->files;
        self::assertFalse($this->provider->apply($this->project(), $this->profile()));
        self::assertStringContainsString('untracked', $this->provider->getLastError());
        self::assertSame($before, $this->remote->files);
    }

    public function test_failed_upload_is_visible_and_retry_completes_it(): void
    {
        $this->local('second.txt', 'second');
        $this->failOnce('rename', '/sites/demo/second.txt');
        self::assertFalse($this->provider->apply($this->project(), $this->profile()));
        self::assertSame('Simulated transport failure.', $this->provider->getLastError());
        self::assertSame('deploying', $this->provider->status($this->project(), $this->profile())['state']);
        self::assertSame(['index.html', 'second.txt'], $this->manifest()['files']);
        self::assertSame('release one', $this->remote->files['/sites/demo/index.html']);
        $this->assertApplied();
        self::assertSame('', $this->provider->getLastError());
        self::assertSame('second', $this->remote->files['/sites/demo/second.txt']);
        self::assertSame('deployed', $this->manifest()['state']);
        self::assertCount(3, $this->remote->files);
    }

    public function test_interrupted_initial_journal_rename_can_be_retried(): void
    {
        $this->failOnce('rename', '/sites/demo/.shipper-ftp.json');
        self::assertFalse($this->provider->apply($this->project(), $this->profile()));
        self::assertArrayHasKey('/sites/demo/.shipper-ftp-state.tmp', $this->remote->files);
        self::assertArrayNotHasKey('/sites/demo/.shipper-ftp.json', $this->remote->files);
        self::assertSame('deploying', $this->provider->status($this->project(), $this->profile())['state']);
        $this->assertApplied();
        self::assertSame('release one', $this->remote->files['/sites/demo/index.html']);
        self::assertSame('deployed', $this->manifest()['state']);
        self::assertArrayNotHasKey('/sites/demo/.shipper-ftp-state.tmp', $this->remote->files);
    }

    public function test_interrupted_initial_journal_can_be_destroyed(): void
    {
        $this->failOnce('rename', '/sites/demo/.shipper-ftp.json');
        self::assertFalse($this->provider->apply($this->project(), $this->profile()));
        self::assertTrue($this->provider->destroy($this->project(), $this->profile()), $this->provider->getLastError());
        self::assertSame([], $this->remote->files);
    }

    public function test_destroy_after_interrupted_upload_removes_its_temporary_files(): void
    {
        $this->failOnce('rename', '/sites/demo/index.html');
        self::assertFalse($this->provider->apply($this->project(), $this->profile()));
        self::assertCount(2, $this->remote->files);
        self::assertTrue($this->provider->destroy($this->project(), $this->profile()), $this->provider->getLastError());
        self::assertSame([], $this->remote->files);
    }

    public function test_retry_removes_temporary_upload_for_a_file_removed_from_the_artifact(): void
    {
        $this->local('removed.txt', 'interrupted upload');
        $this->failOnce('rename', '/sites/demo/removed.txt');
        self::assertFalse($this->provider->apply($this->project(), $this->profile()));
        $temporary = '/sites/demo/.shipper-ftp-tmp-'.hash('sha256', 'removed.txt');
        self::assertArrayHasKey($temporary, $this->remote->files);
        $this->remote->put('/sites/demo/unrelated.txt', 'preserve');
        unlink($this->directory.'/removed.txt');

        $this->assertApplied();
        self::assertArrayNotHasKey($temporary, $this->remote->files);
        self::assertSame(['index.html'], $this->manifest()['files']);
        self::assertTrue($this->provider->destroy($this->project(), $this->profile()));
        self::assertSame(['/sites/demo/unrelated.txt' => 'preserve'], $this->remote->files);
    }

    public function test_failed_stale_cleanup_retains_journal_for_retry(): void
    {
        $this->local('stale.txt', 'old');
        $this->assertApplied();
        unlink($this->directory.'/stale.txt');
        $this->failOnce('delete', '/sites/demo/stale.txt');
        self::assertFalse($this->provider->apply($this->project(), $this->profile()));
        self::assertSame('deploying', $this->manifest()['state']);
        self::assertContains('stale.txt', $this->manifest()['files']);
        $this->assertApplied();
        self::assertArrayNotHasKey('/sites/demo/stale.txt', $this->remote->files);
        self::assertSame(['index.html'], $this->manifest()['files']);
    }

    public function test_failed_destroy_retains_manifest_and_can_be_retried(): void
    {
        $this->local('second.txt', 'second');
        $this->assertApplied();
        $this->failOnce('delete', '/sites/demo/second.txt');
        self::assertFalse($this->provider->destroy($this->project(), $this->profile()));
        self::assertArrayHasKey('/sites/demo/.shipper-ftp.json', $this->remote->files);
        self::assertTrue($this->provider->destroy($this->project(), $this->profile()));
        self::assertSame([], $this->remote->files);
    }

    public function test_destroy_refuses_directory_replacing_tracked_file(): void
    {
        $this->assertApplied();
        unset($this->remote->files['/sites/demo/index.html']);
        $this->remote->directories['/sites/demo/index.html'] = true;
        $this->remote->put('/sites/demo/index.html/other.txt', 'keep');
        self::assertFalse($this->provider->destroy($this->project(), $this->profile()));
        self::assertSame('keep', $this->remote->files['/sites/demo/index.html/other.txt']);
        self::assertArrayHasKey('/sites/demo/.shipper-ftp.json', $this->remote->files);
    }

    public function test_denied_directory_listing_is_not_treated_as_absence(): void
    {
        $this->failOnce('listing', '/');
        self::assertFalse($this->provider->apply($this->project(), $this->profile()));
        self::assertSame([], $this->remote->files);
        self::assertSame(['/' => true], $this->remote->directories);
    }

    #[DataProvider('invalidProviderSettings')]
    public function test_invalid_provider_settings_fail_before_connecting(array $change): void
    {
        $provider = new FtpProvider(array_replace($this->config(), $change), $this->remote);
        self::assertNotSame([], $provider->validate($this->project(), $this->profile()));
        self::assertFalse($provider->apply($this->project(), $this->profile()));
        self::assertSame([], $this->remote->calls);
    }

    public static function invalidProviderSettings(): iterable
    {
        yield 'missing host' => [['host' => '']];
        yield 'host URL' => [['host' => 'ftp://example.com']];
        yield 'host path' => [['host' => 'example.com/path']];
        yield 'host credentials' => [['host' => 'username:password@example.com']];
        yield 'host newline' => [['host' => "example.com\nDELE file"]];
        yield 'unresolved credential' => [['password' => '${FTP_PASSWORD}']];
        yield 'password control byte' => [['password' => "secret\0"]];
        yield 'missing username' => [['username' => '']];
        yield 'invalid protocol' => [['protocol' => 'sftp']];
        yield 'invalid port' => [['port' => 0]];
        yield 'oversized port' => [['port' => 65536]];
        yield 'string port' => [['port' => '21']];
        yield 'invalid timeout' => [['timeout' => -1]];
        yield 'missing ca file' => [['ca_file' => '/not/a/certificate.pem']];
        yield 'insecure TLS override' => [['verify_peer' => false]];
    }

    #[DataProvider('invalidProfileSettings')]
    public function test_invalid_profile_settings_fail_before_connecting(array $settings): void
    {
        $profile = $this->profile($settings);
        self::assertNotSame([], $this->provider->validate($this->project(), $profile));
        self::assertFalse($this->provider->apply($this->project(), $profile));
        self::assertSame([], $this->remote->calls);
    }

    public static function invalidProfileSettings(): iterable
    {
        foreach (['/', 'relative', '/one/../two', '/one/./two', '/one//two', "/one\rDELE two", '/one\\two'] as $path) {
            yield 'remote '.$path => [['remote_path' => $path]];
        }
        yield 'outside local directory' => [['local_path' => '../outside']];
        yield 'absolute local directory' => [['local_path' => '/tmp']];
        yield 'invalid exclusions' => [['exclude' => ['../outside']]];
        yield 'non-list exclusions' => [['exclude' => ['file' => 'index.html']]];
        yield 'unknown option' => [['delete_untracked' => true]];
    }

    public function test_default_exclusions_and_extra_relative_exclusions_are_applied(): void
    {
        foreach (['.env', '.env.production', '.git/config', '.github/workflows/deploy.yml', 'auth.json', 'shipper.yml', 'node_modules/pkg/index.js', '.shipper-ftp.json', 'private/data.txt'] as $file) {
            $this->local($file, 'private');
        }
        $this->local('.well-known/security.txt', 'public');
        $profile = $this->profile(['exclude' => ['private']]);
        self::assertTrue($this->provider->apply($this->project(), $profile), $this->provider->getLastError());
        self::assertSame(['.well-known/security.txt', 'index.html'], $this->manifest()['files']);
    }

    public function test_local_artifact_subdirectory_does_not_upload_project_root_files(): void
    {
        $this->local('dist/index.html', 'built artifact');
        self::assertTrue($this->provider->apply($this->project(), $this->profile(['local_path' => 'dist'])));
        self::assertSame('built artifact', $this->remote->files['/sites/demo/index.html']);
        self::assertSame(['index.html'], $this->manifest()['files']);
    }

    public function test_explicit_current_directory_matches_default_local_artifact_path(): void
    {
        self::assertSame([], $this->provider->validate($this->project(), $this->profile(['local_path' => '.'])));
        self::assertTrue($this->provider->apply($this->project(), $this->profile(['local_path' => '.'])));
        self::assertSame('release one', $this->remote->files['/sites/demo/index.html']);
    }

    public function test_symlink_source_is_rejected_before_connecting(): void
    {
        symlink($this->directory.'/index.html', $this->directory.'/linked.html');
        self::assertFalse($this->provider->apply($this->project(), $this->profile()));
        self::assertStringContainsString('symlink', $this->provider->getLastError());
        self::assertSame([], $this->remote->calls);
    }

    public function test_empty_artifact_does_not_delete_previously_deployed_files(): void
    {
        $this->assertApplied();
        unlink($this->directory.'/index.html');
        $before = $this->remote->files;
        self::assertFalse($this->provider->apply($this->project(), $this->profile()));
        self::assertSame($before, $this->remote->files);
        self::assertStringContainsString('no deployable files', $this->provider->getLastError());
    }

    #[DataProvider('unsafeManifestFiles')]
    public function test_unsafe_manifest_cannot_delete_files_outside_its_ownership(string $file): void
    {
        $this->assertApplied();
        $this->remote->put('/protected.txt', 'keep');
        $manifest = $this->manifest();
        $manifest['files'] = [$file];
        $this->remote->files['/sites/demo/.shipper-ftp.json'] = json_encode($manifest, JSON_THROW_ON_ERROR);
        $before = $this->remote->files;
        self::assertFalse($this->provider->destroy($this->project(), $this->profile()));
        self::assertSame($before, $this->remote->files);
    }

    public static function unsafeManifestFiles(): iterable
    {
        foreach (['../../protected.txt', '/protected.txt', './index.html', 'a//b', '.shipper-ftp.json', 'a/.shipper-ftp-state.tmp', "index.html\nDELE /protected.txt"] as $file) {
            yield $file => [$file];
        }
    }

    public function test_malformed_manifest_is_not_treated_as_no_deployment(): void
    {
        $this->remote->put('/sites/demo/.shipper-ftp.json', '{invalid');
        self::assertFalse($this->provider->destroy($this->project(), $this->profile()));
        self::assertSame('{invalid', $this->remote->files['/sites/demo/.shipper-ftp.json']);
        self::assertStringContainsString('manifest JSON', $this->provider->getLastError());
    }

    public function test_plan_is_local_and_does_not_disclose_credentials(): void
    {
        $plan = $this->provider->plan($this->project(), $this->profile());
        self::assertSame('ftps', $plan['protocol']);
        self::assertSame(1, $plan['file_count']);
        self::assertSame([], $this->remote->calls);
        $encoded = json_encode($plan, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('unit-password', $encoded);
        self::assertStringNotContainsString('unit-username', $encoded);
        $provider = new FtpProvider(array_replace($this->config(), ['password' => "unit-password\n"]), $this->remote);
        self::assertFalse($provider->apply($this->project(), $this->profile()));
        self::assertStringNotContainsString('unit-password', $provider->getLastError());
    }

    public function test_status_and_destroy_do_not_require_the_local_artifact(): void
    {
        $this->assertApplied();
        unlink($this->directory.'/index.html');
        self::assertSame('deployed', $this->provider->status($this->project(), $this->profile())['state']);
        self::assertTrue($this->provider->destroy($this->project(), $this->profile()));
    }

    public function test_plugin_metadata_and_capabilities_match_contracts(): void
    {
        $composer = json_decode(file_get_contents(dirname(__DIR__).'/composer.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame(FtpPlugin::class, $composer['extra']['shipper-plugin']);
        self::assertSame(['ftp' => FtpProvider::class], (new FtpPlugin)->providers());
        self::assertSame('ftp', $this->provider->getName());
        $capabilities = CapabilityManifest::from($this->provider->capabilities())->toArray();
        self::assertSame('supported', $capabilities['app_deploy']['state']);
        self::assertSame('unsupported', $capabilities['server_lifecycle']['state']);
        self::assertSame('unsupported', $capabilities['rollback']['state']);
    }

    public function test_unsupported_project_and_profile_management_are_rejected(): void
    {
        $project = $this->project('demo', ['databases']);
        self::assertStringContainsString('databases', $this->provider->validate($project, $this->profile())[0]);
        $profile = $this->profile();
        $profile->extra['runtime'] = ['type' => 'nodejs'];
        self::assertStringContainsString('runtime', $this->provider->validate($this->project(), $profile)[0]);
        self::assertSame([], $this->remote->calls);
    }

    #[DataProvider('numericFilenames')]
    public function test_numeric_only_root_filename_deploys_updates_and_cleans_up(string $filename): void
    {
        unlink($this->directory.'/index.html');
        $this->local($filename, 'numeric release one');
        self::assertSame(1, $this->provider->plan($this->project(), $this->profile())['file_count']);
        $this->assertApplied();
        self::assertSame('numeric release one', $this->remote->files['/sites/demo/'.$filename]);
        self::assertSame([$filename], $this->manifest()['files']);

        $this->local($filename, 'numeric release two');
        $this->assertApplied();
        self::assertSame('numeric release two', $this->remote->files['/sites/demo/'.$filename]);
        self::assertSame('deployed', $this->provider->status($this->project(), $this->profile())['state']);

        unlink($this->directory.'/'.$filename);
        $this->local('replacement.txt', 'replacement');
        $this->assertApplied();
        self::assertArrayNotHasKey('/sites/demo/'.$filename, $this->remote->files);
        self::assertSame(['replacement.txt'], $this->manifest()['files']);
        self::assertTrue($this->provider->destroy($this->project(), $this->profile()));
        self::assertSame([], $this->remote->files);
    }

    public static function numericFilenames(): iterable
    {
        yield 'zero' => ['0'];
        yield 'positive integer' => ['42'];
        yield 'leading zeros' => ['0042'];
    }

    #[DataProvider('unsupportedResourceMethods')]
    public function test_resource_methods_reject_unsupported_configuration_without_section_metadata(array $configuration, string $diagnostic): void
    {
        $project = $this->semanticProject($configuration);
        self::assertFalse($project->hasSection($diagnostic));
        $errors = $this->provider->validate($project, $this->profile());
        self::assertCount(1, $errors);
        self::assertStringContainsString($diagnostic, $errors[0]);
        self::assertFalse($this->provider->apply($project, $this->profile()));
        self::assertSame([], $this->remote->calls);
    }

    public static function unsupportedResourceMethods(): iterable
    {
        foreach (['databases', 'queues', 'cron', 'daemons', 'networkRules', 'redirects'] as $method) {
            yield $method => [[$method => ['configured' => new \stdClass]], $method];
        }
        foreach (['deployScript', 'phpVersion', 'nginxConfig'] as $method) {
            yield $method => [[$method => 'configured'], $method];
        }
        yield 'website certificate provisioning' => [['ssl' => true], 'website SSL'];
        yield 'website HTTPS redirect provisioning' => [['forceHttps' => true], 'website SSL'];
        yield 'environment variables' => [['environment' => ['APP_KEY' => 'sensitive-value']], 'environment variables'];
    }

    public function test_empty_resource_methods_and_disabled_ssl_allow_file_deployment(): void
    {
        $project = $this->semanticProject();
        self::assertSame([], $this->provider->validate($project, $this->profile()));
        self::assertTrue($this->provider->apply($project, $this->profile()), $this->provider->getLastError());
        self::assertSame('release one', $this->remote->files['/sites/demo/index.html']);
    }

    private function assertApplied(): void
    {
        self::assertTrue($this->provider->apply($this->project(), $this->profile()), $this->provider->getLastError());
    }

    private function config(): array
    {
        return ['host' => 'ftp.example.test', 'username' => 'unit-username', 'password' => 'unit-password'];
    }

    private function project(string $name = 'demo', array $sections = []): object
    {
        return new class($name, $this->directory, $sections)
        {
            public function __construct(private string $name, private string $path, private array $sections) {}

            public function name(): string
            {
                return $this->name;
            }

            public function path(): string
            {
                return $this->path;
            }

            public function hasSection(string $section): bool
            {
                return in_array($section, $this->sections, true);
            }
        };
    }

    /** Mirrors the CLI resource getters when hasSection() carries no metadata for them. */
    private function semanticProject(array $configuration = []): object
    {
        return new class($this->directory, $configuration)
        {
            public function __construct(private string $path, private array $configuration) {}

            public function name(): string
            {
                return 'demo';
            }

            public function path(): string
            {
                return $this->path;
            }

            public function hasSection(string $section): bool
            {
                return false;
            }

            public function databases(): array
            {
                return $this->configuration['databases'] ?? [];
            }

            public function queues(): array
            {
                return $this->configuration['queues'] ?? [];
            }

            public function cron(): array
            {
                return $this->configuration['cron'] ?? [];
            }

            public function daemons(): array
            {
                return $this->configuration['daemons'] ?? [];
            }

            public function networkRules(): array
            {
                return $this->configuration['networkRules'] ?? [];
            }

            public function redirects(): array
            {
                return $this->configuration['redirects'] ?? [];
            }

            public function deployScript(): string
            {
                return $this->configuration['deployScript'] ?? '';
            }

            public function phpVersion(): string
            {
                return $this->configuration['phpVersion'] ?? '';
            }

            public function nginxConfig(): string
            {
                return $this->configuration['nginxConfig'] ?? '';
            }

            public function ssl(): object
            {
                return new class($this->configuration['ssl'] ?? false, $this->configuration['forceHttps'] ?? false)
                {
                    public function __construct(private bool $enabled, private bool $forceHttps) {}

                    public function enabled(): bool
                    {
                        return $this->enabled;
                    }

                    public function forceHttps(): bool
                    {
                        return $this->forceHttps;
                    }
                };
            }

            public function environment(): object
            {
                return new class($this->configuration['environment'] ?? [])
                {
                    public function __construct(private array $variables) {}

                    public function isEmpty(): bool
                    {
                        return $this->variables === [];
                    }
                };
            }
        };
    }

    private function profile(array $ftp = [], string $name = 'production'): object
    {
        return new class($ftp + ['remote_path' => '/sites/demo'], $name)
        {
            public array $extra = [];

            public function __construct(private array $ftp, private string $name) {}

            public function name(): string
            {
                return $this->name;
            }

            public function get(string $key): mixed
            {
                return $key === 'ftp' ? $this->ftp : ($this->extra[$key] ?? null);
            }

            public function server(): mixed
            {
                return null;
            }
        };
    }

    private function local(string $path, string $contents): void
    {
        $target = $this->directory.'/'.$path;
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        file_put_contents($target, $contents);
    }

    private function manifest(): array
    {
        return json_decode($this->remote->files['/sites/demo/.shipper-ftp.json'], true, 32, JSON_THROW_ON_ERROR);
    }

    private function failOnce(string $operation, string $path): void
    {
        $this->remote->before = function (string $actualOperation, string $actualPath) use ($operation, $path): void {
            if ($operation === $actualOperation && $path === $actualPath) {
                $this->remote->before = null;
                throw new \RuntimeException('Simulated transport failure.');
            }
        };
    }
}
