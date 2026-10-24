#!/usr/bin/env python3
"""Build a deterministic, installable WordPress release asset from tracked source."""
import argparse
import hashlib
import re
import subprocess
import zipfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
p = argparse.ArgumentParser()
p.add_argument('--tag', help='Release tag; must equal v<Version> or <Version>')
p.add_argument('--output', type=Path, default=root / 'dist')
a = p.parse_args()
main = (root / 'easy-slug-protect.php').read_text()
header = re.findall(r'^ \* Version: (\S+)$', main, re.M)
constant = re.findall(r"define\('ESP_VERSION', '([^']+)'\);", main)
if len(header) != 1 or header != constant or not re.fullmatch(r'(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)', header[0]):
    p.error('Version header and ESP_VERSION must match and be stable SemVer')
version = header[0]
if not re.search(r'^ \* Update URI: https://github.com/ponpaku/wp-easy-slug-protect$', main, re.M):
    p.error('unexpected Update URI')
if a.tag and a.tag not in (version, 'v' + version):
    p.error('tag/header/ESP_VERSION mismatch')
tracked = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().split('\0')
files = sorted(f for f in tracked if f in ('easy-slug-protect.php', 'uninstall.php') or f.startswith(('includes/', 'admin/', 'front/', 'languages/')))
required = ['easy-slug-protect.php', 'uninstall.php', 'includes/class-esp-migrator.php', 'includes/class-esp-updater.php', 'includes/class-esp-protection-state.php']
if not all(f in files for f in required):
    p.error('required runtime files missing from tracked source')
a.output.mkdir(parents=True, exist_ok=True)
archive = a.output / f'easy-slug-protect-{version}.zip'
with zipfile.ZipFile(archive, 'w', compression=zipfile.ZIP_DEFLATED) as z:
    for f in files:
        source = root / f
        if source.is_symlink() or not source.is_file():
            p.error(f'nonregular distribution file: {f}')
        info = zipfile.ZipInfo('easy-slug-protect/' + f, date_time=(2026, 1, 1, 0, 0, 0))
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        z.writestr(info, source.read_bytes())
with zipfile.ZipFile(archive) as z:
    assert z.testzip() is None
    assert 'easy-slug-protect/easy-slug-protect.php' in z.namelist()
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(archive.with_suffix('.zip.sha256')).write_text(f'{digest}  {archive.name}\n')
print(f'{archive}\nsha256:{digest}')
