"""Validate every asset before extracting a commit-specific deployment bundle."""
import hashlib
import json
from pathlib import Path
import sys
import tarfile

PREFIXES = ('public/build/', 'public/sdk/', 'public/css/filament/',
            'public/js/filament/', 'public/fonts/filament/')


def install(commit, archive, destination):
    with tarfile.open(archive, 'r:gz') as bundle:
        members = bundle.getmembers()
        names = [m.name for m in members]
        if len(names) != len(set(names)) or any(not m.isfile() for m in members):
            raise ValueError('Duplicate or non-file archive entry')
        manifest = json.load(bundle.extractfile('release-assets.json'))
        if manifest['commit'] != commit:
            raise ValueError('Asset commit does not match source')
        if set(names) != set(manifest['files']) | {'release-assets.json'}:
            raise ValueError('Asset manifest does not match archive')
        for name, expected in manifest['files'].items():
            if not name.startswith(PREFIXES) or '..' in Path(name).parts:
                raise ValueError('Unexpected asset path')
            if hashlib.sha256(bundle.extractfile(name).read()).hexdigest() != expected:
                raise ValueError('Asset checksum mismatch')
        for required in ('public/build/manifest.json', 'public/sdk/integrity.json', 'public/sdk/paykr.min.js'):
            if required not in manifest['files']:
                raise ValueError('Required asset missing')
        for member in members:
            target = Path(destination) / member.name
            if not target.resolve().is_relative_to(Path(destination).resolve()):
                raise ValueError('Asset destination escapes release')
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(bundle.extractfile(member).read())
            target.chmod(0o644)


if __name__ == '__main__':
    install(*sys.argv[1:])
