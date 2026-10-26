#!/usr/bin/env python3
"""Negative controls: a malformed ZIP with a matching fresh checksum is still rejected."""
import hashlib
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
version = next(
    line.split("Version:", 1)[1].strip()
    for line in (root / "easy-slug-protect.php").read_text().splitlines()
    if line.startswith(" * Version:")
)
original = root / "dist" / f"easy-slug-protect-{version}.zip"
if not original.is_file():
    raise SystemExit("Missing built release ZIP: " + str(original))

with zipfile.ZipFile(original) as z:
    names = z.namelist()
    contents = {name: z.read(name) for name in names}
bootstrap = "easy-slug-protect/includes/class-esp-core.php"
if bootstrap not in contents:
    raise SystemExit("Baseline release ZIP has no class-esp-core.php")

with tempfile.TemporaryDirectory(prefix="esp44-negative-zip-") as directory:
    for kind in ("missing-core", "modified-core", "extra-entry"):
        wrong = dict(contents)
        if kind == "missing-core":
            del wrong[bootstrap]
        elif kind == "modified-core":
            wrong[bootstrap] = b"<?php // tampered bootstrap\n"
        else:
            wrong["easy-slug-protect/tests/unexpected.txt"] = b"not a release file"
        bad_zip = Path(directory) / "easy-slug-protect-corrupt.zip"
        with zipfile.ZipFile(bad_zip, "w", zipfile.ZIP_DEFLATED) as z:
            for name, content in wrong.items():
                z.writestr(name, content)
        # The digest corresponds to the *bad* asset. Hash integrity alone
        # must not be mistaken for a complete and verified release package.
        digest = hashlib.sha256(bad_zip.read_bytes()).hexdigest()
        bad_zip.with_suffix(".zip.sha256").write_text(f"{digest}  {bad_zip.name}\n")
        result = subprocess.run(
            [sys.executable, str(root / "tools/build-release.py"),
             "--tag", "v" + version, "--verify-zip", str(bad_zip)],
            cwd=root, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
            check=False,
        )
        if result.returncode == 0:
            raise SystemExit(f"FAIL: validated malformed ZIP ({kind})")
        print(f"PASS: release gate rejects {kind}, even with matching SHA-256")

print("All release package negative controls passed.")
