# Shipper CLI FTP/FTPS provider

Deploy prebuilt websites and application files to FTP or explicit FTPS servers
using [Shipper CLI](https://github.com/shippercli/cli). Shipper discovers the
provider through Composer's `shipper-plugin` metadata.

## Install

Requires PHP 8.3+ with `ext-curl`, an FTP server supporting MLSD, and write,
rename, list and delete permissions on a dedicated deployment directory.

```bash
composer config repositories.shipper-ftp git https://github.com/shippercli/provider-ftp.git
composer require 'shippercli/cli:^1.0.10' 'shippercli/provider-ftp:^1.0'
```

Use `vendor/bin/shipper` with a local installation. For a global installation,
use `composer global config` and `composer global require` with the same
arguments, then run `shipper`. The Git repository installation works without
Packagist registration. Installing this package requires the Composer version
of Shipper, which supports external provider discovery.

## Configure

Build locally before applying. For example, run `npm ci && npm run build` and
upload the resulting `dist` directory.

```yaml
providers:
  ftp:
    host: ftp.example.com
    port: 21
    protocol: ftps
    username: "${FTP_USERNAME}"
    password: "${FTP_PASSWORD}"
    timeout: 30

projects:
  website:
    provider: ftp
    path: .
    profiles:
      production:
        branch: main
        ftp:
          local_path: dist
          remote_path: /public_html/website
          exclude:
            - private
            - debug.txt
```

`ftps` is the default and requires explicit TLS on **both** the control and
data connections. Certificates and hostnames are always verified. For a private
CA, set `providers.ftp.ca_file` to a readable PEM certificate bundle. Implicit
FTPS and SFTP are not supported. `protocol: ftp` explicitly opts into plaintext
transport, including plaintext credentials.

`remote_path` is absolute within the FTP account's filesystem namespace. It
must identify a dedicated non-root directory. A chrooted FTP account sees its
own root as `/`. The first deployment requires an empty directory; missing
directories are created. The provider does not adopt an existing website.

`local_path` defaults to `.` relative to the project's `path`. It must resolve
inside that project. `exclude` extends the built-in exclusions using exact
relative paths and directory prefixes, without glob patterns. Git metadata,
`.github`, `node_modules`, `.env*`, `auth.json`, `shipper.yml`, `shipper.yaml`,
PHPUnit caches and reserved `.shipper-ftp*` files are always excluded. Review
your build output for other secrets before deploying. Symlinks and special
files are rejected. Empty directories are not uploaded.

## Operate

```bash
vendor/bin/shipper validate
vendor/bin/shipper plan website --profile=production
vendor/bin/shipper apply website --profile=production
vendor/bin/shipper status website --profile=production
vendor/bin/shipper destroy website --profile=production
```

The plan inspects the local artifact without connecting to the server.
Apply checks remote ownership, writes a deployment journal, uploads each file
to a temporary name, renames it into place, removes stale tracked files, then
marks the deployment complete. Status reads saved deployment state; it does
not check application health or detect later manual edits.

Destroy deletes only files listed in the matching project/profile manifest
and their deterministic upload temporary files. It preserves unrelated files
and directories, including the deployment root. Tracked files are treated as
deployment-owned: apply overwrites them and destroy deletes them even if they
were edited manually. Back up anything you need before using these commands.

See [operations and recovery](docs/operations.md) and
[capabilities](docs/capabilities.md).

## Development

```bash
composer install
composer validate --strict
composer test
python3 -m venv .venv
.venv/bin/pip install -r tests/integration/requirements.txt
.venv/bin/python tests/integration/run.py
```

The integration tests start disposable loopback FTP and FTPS servers, compare
real server files after deploy/update/destroy, and verify TLS failures and
credential redaction. Python 3.12+, OpenSSL and PHP are required. GitHub CI runs
unit and transport integration tests on PHP 8.3, 8.4 and 8.5.

MIT licensed.
