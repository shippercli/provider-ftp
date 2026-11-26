import json
import sys
import zipfile

with zipfile.ZipFile(sys.argv[1]) as archive:
    paths = archive.namelist()
    assert 'src/FtpProvider.php' in paths and 'src/FtpPlugin.php' in paths
    assert 'README.md' in paths and 'LICENSE' in paths and 'meta.json' in paths
    assert not any(p.startswith(('vendor/', 'tests/', '.github/')) or p in ('auth.json', 'composer.lock') or p.startswith('.env') for p in paths)
    metadata = json.loads(archive.read('composer.json'))
    assert metadata['extra']['shipper-plugin'] == 'ShipperCli\\ProviderFtp\\FtpPlugin'
print('Distribution contents and plugin metadata verified.')
