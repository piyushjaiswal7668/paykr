import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import subprocess
import tarfile
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]


def module(name, filename):
    spec = importlib.util.spec_from_file_location(name, ROOT / 'scripts' / filename)
    result = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(result)
    return result


assets = module('package', 'package-release-assets.py')
installer = module('install', 'install-release-assets.py')
health = module('health', 'check-deploy-health.py')


class DeployTest(unittest.TestCase):
    def test_health_requires_clock(self):
        self.assertFalse(health.healthy({'status': 'UP'}))
        self.assertFalse(health.healthy({'status': 'UP', 'clock': {'status': 'RUNNING', 'healthy': False}}))
        self.assertTrue(health.healthy({'status': 'UP', 'clock': {'status': 'RUNNING', 'healthy': True}}))

    def test_archive_rejects_wrong_commit_and_tampering(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            source = self.source(root)
            archive = assets.package(source, root / 'output', 'a' * 40)
            installer.install('a' * 40, archive, root / 'installed')
            with self.assertRaises(ValueError):
                installer.install('b' * 40, archive, root / 'wrong')
            with tarfile.open(archive) as bundle:
                entries = [(m, bundle.extractfile(m).read()) for m in bundle.getmembers()]
            with tarfile.open(archive, 'w:gz') as bundle:
                for member, data in entries:
                    if member.name.endswith('paykr.min.js'):
                        data = b'tampered'
                        member.size = len(data)
                    bundle.addfile(member, io.BytesIO(data))
            with self.assertRaises(ValueError):
                installer.install('a' * 40, archive, root / 'tampered')
            self.assertFalse((root / 'tampered').exists())

    def source(self, root):
        source = root / 'source'
        for directory in (*assets.ROOTS, 'public/build/assets', 'storage', 'bootstrap/cache', 'deploy', 'scripts', 'services/whatsapp-sidecar'):
            (source / directory).mkdir(parents=True, exist_ok=True)
        for name in ('public/build/manifest.json', 'public/sdk/integrity.json', 'public/sdk/paykr.min.js'):
            (source / name).write_text('fixture')
        shutil.copy(ROOT / 'scripts/install-release-assets.py', source / 'scripts')
        shutil.copy(ROOT / 'scripts/check-deploy-health.py', source / 'scripts')
        return source

    def test_activation_and_failure_recovery(self):
        for fail, pending, mode, changed_sidecar in ((False, '0', 'graceful', False), (True, '0', 'graceful', False), (False, '1', 'graceful', False), (False, '0', 'graceful', True), (False, '0', 'maintenance', False), (True, '0', 'maintenance', False), (True, '1', 'maintenance', False)):
            with self.subTest(fail=fail, pending=pending, mode=mode, changed_sidecar=changed_sidecar), tempfile.TemporaryDirectory() as temporary:
                root = Path(temporary)
                source = self.source(root)
                if changed_sidecar:
                    (source / 'services/whatsapp-sidecar/index.js').write_text('changed sender')
                sha = 'a' * 40
                incoming = root / 'incoming' / (sha + '-123-1')
                incoming.mkdir(parents=True)
                old = root / 'releases/old'
                old.mkdir(parents=True)
                (old / 'services/whatsapp-sidecar').mkdir(parents=True)
                (old / 'public/build/assets').mkdir(parents=True)
                (old / 'public/build/assets/old-hash.js').write_text('old asset')
                (root / 'current').symlink_to(old)
                for name in ('storage', 'sidecar-data'):
                    (root / 'shared' / name).mkdir(parents=True)
                (root / 'shared/.env').write_text('APP_KEY=preserve-me')
                (root / 'shared/.env').chmod(0o600)
                archive = assets.package(source, root / 'output', sha)
                shutil.copy(archive, incoming / 'assets.tar.gz')
                with tarfile.open(incoming / 'source.tar.gz', 'w:gz') as bundle:
                    for path in source.iterdir():
                        bundle.add(path, arcname=path.name)
                (incoming / 'SHA256SUMS').write_text(''.join(
                    hashlib.sha256((incoming / name).read_bytes()).hexdigest() + '  ' + name + '\n'
                    for name in ('assets.tar.gz', 'source.tar.gz')))
                bin_dir = root / 'bin'
                bin_dir.mkdir()
                shim = '''#!/usr/bin/env python3
import os, pathlib, sys, hashlib
name=pathlib.Path(sys.argv[0]).name
args=sys.argv[1:]
root=pathlib.Path(os.environ['PAYKR_ROOT'])
with (root/'commands').open('a') as f: f.write(name+' '+' '.join(args)+'\\n')
if name=='sudo':
    args=args[1:] if args[0]=='-n' else args
    if args[0]=='-l': sys.exit(0)
    if args[0]=='chgrp': sys.exit(0)
    os.execvp(args[0],args)
if name=='sha256sum':
    for line in pathlib.Path('SHA256SUMS').read_text().splitlines():
        expected,path=line.split()
        if hashlib.sha256(pathlib.Path(path).read_bytes()).hexdigest()!=expected: sys.exit(1)
if name=='php':
    if args[0].endswith('pending-migrations.php'): print(os.environ['PENDING'])
    if args[0].endswith('application-url.php'): print('https://example.test')
if name=='composer': pathlib.Path('vendor').mkdir(exist_ok=True)
if name=='npm': pathlib.Path('services/whatsapp-sidecar/node_modules').mkdir(exist_ok=True)
if name=='mysqldump': print('DATABASE BACKUP')
if name=='curl':
    print('{"status":"UP","clock":{"status":"RUNNING","healthy":'+('false' if os.environ['FAIL']=='1' else 'true')+'}}')
'''
                for name in ('sudo', 'systemctl', 'php', 'composer', 'npm', 'mysqldump', 'curl', 'sleep', 'flock', 'sha256sum'):
                    path = bin_dir / name
                    path.write_text(shim)
                    path.chmod(0o755)
                # GNU mv -T is not available on macOS; retain real atomic rename semantics.
                mv = bin_dir / 'mv'
                mv.write_text('#!/usr/bin/env python3\nimport os,sys\na=[x for x in sys.argv[1:] if not x.startswith("-")]\nos.rename(a[0],a[1])\n')
                mv.chmod(0o755)
                result = subprocess.run(['bash', str(ROOT / 'deploy/oracle-deploy.sh'), sha, str(incoming), mode],
                    env={**os.environ, 'PAYKR_ROOT': str(root), 'PATH': str(bin_dir)+':'+os.environ['PATH'],
                         'FAIL': str(int(fail)), 'PENDING': pending}, capture_output=True, text=True)
                commands = (root / 'commands').read_text()
                rejected = mode == 'graceful' and (pending != '0' or changed_sidecar)
                self.assertEqual(result.returncode == 0, not fail and not rejected, result.stdout + result.stderr)
                self.assertEqual((root / 'shared/.env').read_text(), 'APP_KEY=preserve-me')
                self.assertEqual((root / 'shared/.env').stat().st_mode & 0o777, 0o600)
                self.assertEqual((root / 'backups' / incoming.name / 'database.sql.gz').exists(), not rejected)
                self.assertEqual((root / 'current').resolve() == old.resolve(), rejected or (fail and pending == '0'))
                self.assertNotIn('db:seed', commands)
                if mode == 'graceful':
                    self.assertNotIn('artisan down', commands)
                    self.assertNotIn('artisan up', commands)
                    self.assertNotIn('systemctl stop', commands)
                    self.assertNotIn('restart paykr@whatsapp-sidecar', commands)
                    self.assertNotIn('artisan migrate', commands)
                    if not rejected:
                        self.assertIn('systemctl reload php8.3-fpm', commands)
                        self.assertTrue((root / 'releases' / incoming.name / 'public/build/assets/old-hash.js').exists())
                else:
                    self.assertNotIn('systemctl stop php8.3-fpm', commands)
                if fail and pending == '1' and mode == 'maintenance':
                    self.assertIn('Maintenance remains ON', result.stderr)
                    self.assertTrue(commands.rfind('artisan down') > commands.rfind('artisan up'))
