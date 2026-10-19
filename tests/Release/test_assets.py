import hashlib
import importlib.util
import json
from pathlib import Path
import tarfile
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('assets', Path(__file__).resolve().parents[2] / 'scripts/package-release-assets.py')
assets = importlib.util.module_from_spec(spec)
spec.loader.exec_module(assets)


class ReleaseAssetsTest(unittest.TestCase):
    def test_allowlist_checksums_and_unsafe_input_rejection(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            for directory in assets.ROOTS:
                (root / directory).mkdir(parents=True)
            for name in ('public/build/manifest.json', 'public/sdk/integrity.json', 'public/sdk/paykr.min.js'):
                (root / name).write_text('fixture')
            (root / '.env').write_text('SECRET=must-not-ship')
            commit = 'a' * 40
            archive = assets.package(root, root / 'output', commit)
            with tarfile.open(archive) as bundle:
                self.assertNotIn('.env', bundle.getnames())
                manifest = json.load(bundle.extractfile('release-assets.json'))
                self.assertEqual(manifest['commit'], commit)
                self.assertEqual(set(bundle.getnames()), set(manifest['files']) | {'release-assets.json'})
                for name, digest in manifest['files'].items():
                    self.assertEqual(hashlib.sha256(bundle.extractfile(name).read()).hexdigest(), digest)
            self.assertEqual((root / 'output/SHA256SUMS').read_text().split()[0], hashlib.sha256(archive.read_bytes()).hexdigest())
            (root / 'public/build/leaked-secret').symlink_to(root / '.env')
            with self.assertRaises(ValueError):
                assets.package(root, root / 'output', commit)
            (root / 'public/build/leaked-secret').unlink()
            (root / 'public/build/manifest.json').unlink()
            with self.assertRaises(ValueError):
                assets.package(root, root / 'output', commit)
            with self.assertRaises(ValueError):
                assets.package(root, root / 'output', '../invalid')
