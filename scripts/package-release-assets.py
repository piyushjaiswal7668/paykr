"""Package only public build assets; no environment, vendor or runtime data."""
import hashlib
import io
import json
from pathlib import Path
import re
import subprocess
import tarfile

ROOTS = (
    'public/build', 'public/sdk', 'public/css/filament',
    'public/js/filament', 'public/fonts/filament',
)


def package(root, output, commit):
    if not re.fullmatch(r'[0-9a-f]{40}', commit):
        raise ValueError('Expected a full Git commit SHA')
    for required in ('public/build/manifest.json', 'public/sdk/integrity.json', 'public/sdk/paykr.min.js'):
        if not (root / required).is_file():
            raise ValueError(f'Missing required build output: {required}')
    files = []
    for name in ROOTS:
        directory = root / name
        if not directory.is_dir() or directory.is_symlink():
            raise ValueError(f'Missing or unsafe asset directory: {name}')
        for path in sorted(directory.rglob('*')):
            if path.is_symlink() or (not path.is_dir() and not path.is_file()):
                raise ValueError(f'Unsafe asset entry: {path}')
            if path.is_file():
                files.append(path)
    manifest = {'commit': commit, 'files': {
        path.relative_to(root).as_posix(): hashlib.sha256(path.read_bytes()).hexdigest()
        for path in files
    }}
    output.mkdir(parents=True, exist_ok=True)
    archive = output / f'paykr-assets-{commit}.tar.gz'
    with tarfile.open(archive, 'w:gz') as bundle:
        for path in files:
            bundle.add(path, arcname=path.relative_to(root).as_posix(), recursive=False)
        metadata = json.dumps(manifest, indent=2).encode()
        entry = tarfile.TarInfo('release-assets.json')
        entry.size = len(metadata)
        entry.mode = 0o644
        bundle.addfile(entry, io.BytesIO(metadata))
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    (output / 'SHA256SUMS').write_text(f'{digest}  {archive.name}\n')
    return archive


if __name__ == '__main__':
    root = Path(__file__).resolve().parent.parent
    commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root, text=True).strip()
    print(package(root, root / 'dist', commit))
