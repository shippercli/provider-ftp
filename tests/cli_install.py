"""Install alongside the released CLI, then exercise real CLI FTP/FTPS flows."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile

package = Path(__file__).resolve().parents[1]
published = '--published' in sys.argv
with tempfile.TemporaryDirectory(prefix='shipper-ftp-consumer-') as directory:
    root = Path(directory)
    repository = ({'type': 'git', 'url': 'https://github.com/shippercli/provider-ftp.git'} if published else {
        'type': 'path', 'url': str(package), 'options': {'symlink': False, 'versions': {'shippercli/provider-ftp': '1.0.0'}}
    })
    (root / 'composer.json').write_text(json.dumps({
        'name': 'shippercli/ftp-integration-consumer',
        'repositories': [repository],
        'require': {'shippercli/cli': '^1.0.10', 'shippercli/provider-ftp': '^1.0'},
        'config': {'allow-plugins': False}
    }))
    subprocess.run(['composer', 'install', '--no-dev', '--prefer-dist', '--no-interaction', '--no-progress'], cwd=root, check=True)
    subprocess.run(['composer', 'check-platform-reqs', '--no-dev'], cwd=root, check=True)
    env = os.environ | {'FTP_TEST_CLI': str(root / 'vendor/bin/shipper')}
    subprocess.run([sys.executable, str(package / 'tests/integration/run.py')], cwd=root, env=env, check=True)
    print('Released CLI plugin discovery, validate, plan, apply, status and destroy verified.')
