"""Real loopback FTP/explicit FTPS lifecycle and TLS rejection tests. No external account."""
import json
import logging
import os
from pathlib import Path
import subprocess
import tempfile
import threading

from pyftpdlib.authorizers import DummyAuthorizer
from pyftpdlib.handlers import FTPHandler, TLS_FTPHandler
from pyftpdlib.servers import FTPServer

logging.disable(logging.CRITICAL)
PACKAGE = Path(__file__).resolve().parents[2]


def run_client(config, source, action):
    if os.environ.get('FTP_TEST_CLI'):
        configuration = source.parent / 'shipper-test.json'
        configuration.write_text(json.dumps({
            'providers': {'ftp': config},
            'projects': {'integration': {'provider': 'ftp', 'path': str(source), 'profiles': {
                'test': {'branch': 'main', 'ftp': {'remote_path': '/site', 'local_path': '.'}}
            }}}
        }))
        def cli(command, failure=False):
            args = ['php', os.environ['FTP_TEST_CLI'], command]
            if command != 'validate':
                args += ['integration', '--profile=test']
            if command in ('apply', 'destroy'):
                args += ['--force']
            result = subprocess.run(args + ['--config=' + str(configuration), '--no-ansi', '--no-interaction'], text=True, capture_output=True)
            output = result.stdout + result.stderr
            assert config['password'] not in output, 'CLI exposed FTP password'
            if failure:
                assert result.returncode != 0, 'CLI accepted a connection that should fail'
            elif result.returncode != 0:
                raise RuntimeError(output)
        if action == 'failure':
            cli('apply', failure=True)
        else:
            cli('validate')
            if action == 'apply':
                cli('plan')
            cli(action)
            cli('status')
        return
    env = os.environ | {"FTP_TEST_CONFIG": json.dumps(config), "FTP_TEST_SOURCE": str(source)}
    subprocess.run(["php", str(PACKAGE / "tests/integration/client.php"), action], env=env, check=True)


with tempfile.TemporaryDirectory(prefix="shipper-ftp-") as temporary:
    directory = Path(temporary)
    certificate = directory / "certificate.pem"
    key = directory / "key.pem"
    subprocess.run([
        "openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
        "-subj", "/CN=localhost", "-addext", "subjectAltName=DNS:localhost",
        "-keyout", str(key), "-out", str(certificate),
    ], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)

    for protocol in ("ftp", "ftps"):
        root = directory / protocol
        root.mkdir()
        source = directory / (protocol + "-source")
        source.mkdir()
        (source / "assets").mkdir()
        (source / "index.html").write_text("release one")
        (source / "123").write_text("numeric filename")
        (source / "space name.txt").write_text("space filename")
        (source / "assets/old.txt").write_text("old asset")
        (source / ".env").write_text("DO_NOT_UPLOAD=secret")
        authorizer = DummyAuthorizer()
        authorizer.add_user("shipper", "test-password-only", str(root), perm="elradfmwMT")
        handler = type("TestHandler", (TLS_FTPHandler if protocol == "ftps" else FTPHandler,), {})
        handler.authorizer = authorizer
        if protocol == "ftps":
            handler.certfile = str(certificate)
            handler.keyfile = str(key)
            handler.tls_control_required = True
            handler.tls_data_required = True
        server = FTPServer(("127.0.0.1", 0), handler)
        port = server.socket.getsockname()[1]
        thread = threading.Thread(target=server.serve_forever, kwargs={"timeout": 0.05}, daemon=True)
        thread.start()
        config = {"host": "localhost", "port": port, "username": "shipper", "password": "test-password-only", "protocol": protocol, "timeout": 5}
        if protocol == "ftps":
            # Default system trust must reject our private fixture certificate.
            run_client(config, source, "failure")
            config["ca_file"] = str(certificate)
            run_client(config | {"host": "127.0.0.1"}, source, "failure")
            run_client(config | {"protocol": "ftp"}, source, "failure")
        else:
            # FTPS must fail closed against a server without TLS support.
            run_client(config | {"protocol": "ftps"}, source, "failure")
        run_client(config | {"password": "wrong-password"}, source, "failure")
        run_client(config, source, "apply")
        assert (root / "site/index.html").read_text() == "release one"
        assert (root / "site/123").read_text() == "numeric filename"
        assert (root / "site/space name.txt").read_text() == "space filename"
        assert not (root / "site/.env").exists()
        (root / "site/customer.txt").write_text("keep me")
        (source / "index.html").write_text("release two")
        (source / "assets/old.txt").unlink()
        (source / "assets/new.txt").write_text("new asset")
        run_client(config, source, "apply")
        assert (root / "site/index.html").read_text() == "release two"
        assert not (root / "site/assets/old.txt").exists()
        assert (root / "site/assets/new.txt").read_text() == "new asset"
        assert (root / "site/customer.txt").read_text() == "keep me"
        manifest = json.loads((root / "site/.shipper-ftp.json").read_text())
        assert manifest["state"] == "deployed" and len(manifest["files"]) == 4
        assert all(isinstance(name, str) for name in manifest['files'])
        run_client(config, source, "destroy")
        run_client(config, source, "destroy")
        assert (root / "site/customer.txt").read_text() == "keep me"
        assert not (root / "site/index.html").exists()
        assert not (root / "site/assets/new.txt").exists()
        assert not (root / "site/123").exists()
        assert not (root / "site/space name.txt").exists()
        assert not (root / "site/.shipper-ftp.json").exists()
        server.close_all()
        thread.join(timeout=5)
        print(protocol.upper() + " lifecycle verified against real server files.")
