#!/usr/bin/env python3
"""Build and verify exactly the WordPress package delivered by a Stable Release."""
import argparse
import hashlib
import re
import subprocess
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PREFIX = "easy-slug-protect/"


def fail(reason):
    raise ValueError(reason)


def expected_source_files():
    # Only tracked runtime files enter the distribution. No tests, CI or docs.
    tracked = subprocess.check_output(["git", "ls-files", "-z"], cwd=ROOT).decode().split("\0")
    files = sorted(f for f in tracked if f in ("easy-slug-protect.php", "uninstall.php")
                   or f.startswith(("includes/", "admin/", "front/", "languages/")))
    return files


def required_files(main):
    # Derive boot requirements from the actual load_dependencies() contract
    # instead of maintaining a second list when ESP gains a new PHP class.
    direct = re.findall(r"""\brequire(?:_once)?\s+ESP_PATH\s*\.\s*(['"])([^'"]+)\1""", main)
    requirements = {"easy-slug-protect.php", "uninstall.php"} | {path for _, path in direct}
    if len(direct) < 10 or "includes/class-esp-core.php" not in requirements or (
        "admin/classes/class-esp-admin-core.php" not in requirements
    ):
        fail("Cannot enumerate current ESP boot requirements")
    for path in requirements:
        if path.startswith("/") or "\\" in path or any(p in ("", ".", "..") for p in path.split("/")):
            fail(f"Invalid boot dependency: {path}")
    return requirements


def verify_zip(archive, expected, required):
    if not archive.is_file():
        fail(f"Distribution ZIP missing: {archive}")
    with zipfile.ZipFile(archive) as z:
        if z.testzip() is not None:
            fail("ZIP CRC invalid")
        members = z.infolist()
        actual = [entry.filename for entry in members]
        want = {PREFIX + f for f in expected}
        if len(actual) != len(want) or set(actual) != want:
            fail("ZIP contains missing, duplicate or unexpected runtime entries")
        for entry in members:
            if entry.is_dir() or ((entry.external_attr >> 16) & 0o170000) != 0o100000:
                fail(f"ZIP entry is not a regular file: {entry.filename}")
        if not all(PREFIX + name in actual for name in required):
            fail("ZIP is missing a mandatory bootstrap file")
        if z.read(PREFIX + "easy-slug-protect.php") != (ROOT / "easy-slug-protect.php").read_bytes():
            fail("ZIP entrypoint differs from reviewed source")
        # Check *every* packaged byte against the tracked source, not just names.
        for name in expected:
            if z.read(PREFIX + name) != (ROOT / name).read_bytes():
                fail(f"ZIP file differs from reviewed source: {name}")
    print(f"PASS: verified {archive.name}: {len(expected)} exact source files, bootstrap complete")


def main():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--tag", help="Release tag; must equal v<Version> or <Version>")
    p.add_argument("--output", type=Path, default=ROOT / "dist")
    p.add_argument("--verify-zip", type=Path, help="Validate an existing ZIP without rebuilding it")
    args = p.parse_args()
    try:
        main_php = (ROOT / "easy-slug-protect.php").read_text()
        header = re.findall(r"^ \* Version: (\S+)$", main_php, re.M)
        constant = re.findall(r"define\('ESP_VERSION', '([^']+)'\);", main_php)
        if len(header) != 1 or header != constant or not re.fullmatch(
            r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)", header[0]
        ):
            fail("Version header and ESP_VERSION must match stable SemVer")
        version = header[0]
        if not re.search(
            r"^ \* Update URI: https://github.com/ponpaku/wp-easy-slug-protect$",
            main_php, re.M
        ):
            fail("Unexpected Update URI")
        if args.tag and args.tag not in (version, "v" + version):
            fail("Tag/header/ESP_VERSION mismatch")

        files = expected_source_files()
        needed = required_files(main_php)
        if not needed.issubset(files):
            fail(f"Missing tracked boot files: {sorted(needed - set(files))}")
        for name in files:
            item = ROOT / name
            if item.is_symlink() or not item.is_file():
                fail(f"Missing or nonregular runtime source: {name}")

        if args.verify_zip:
            verify_zip(args.verify_zip, files, needed)
            return
        args.output.mkdir(parents=True, exist_ok=True)
        archive = args.output / f"easy-slug-protect-{version}.zip"
        with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED) as z:
            for name in files:
                info = zipfile.ZipInfo(PREFIX + name, date_time=(2026, 1, 1, 0, 0, 0))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.external_attr = 0o100644 << 16
                z.writestr(info, (ROOT / name).read_bytes())
        verify_zip(archive, files, needed)
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        archive.with_suffix(".zip.sha256").write_text(f"{digest}  {archive.name}\n")
        print(f"{archive}\nsha256:{digest}")
    except (OSError, ValueError, zipfile.BadZipFile) as exc:
        p.error(str(exc))


if __name__ == "__main__":
    main()
