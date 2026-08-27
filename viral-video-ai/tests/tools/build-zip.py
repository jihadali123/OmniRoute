#!/usr/bin/env python3
"""Build the installable Viral Video AI plugin ZIP."""

import hashlib
import os
import sys
import zipfile

REPO = "/home/user/OmniRoute"
SRC = os.path.join(REPO, "viral-video-ai")
SKIP_DIRS = {".git", "node_modules", "vendor", "__pycache__", ".toolbin"}
TOP = "viral-video-ai"


def collect(root):
    files = []
    for dirpath, dirnames, filenames in os.walk(root):
        dirnames[:] = sorted(d for d in dirnames if d not in SKIP_DIRS)
        for name in sorted(filenames):
            if name.endswith((".pyc", ".log")):
                continue
            full = os.path.join(dirpath, name)
            rel = os.path.relpath(full, root)
            files.append((rel, full))
    return sorted(files)


def main():
    version = None
    with open(os.path.join(SRC, "viral-video-ai.php"), encoding="utf-8") as handle:
        for line in handle:
            if " * Version:" in line:
                version = line.split(":")[-1].strip()
                break
    if not version:
        print("no version found", file=sys.stderr)
        return 2

    out = os.path.join(REPO, "viral-video-ai-%s.zip" % version)
    dist = "/home/user/dist/viral-video-ai-%s.zip" % version
    os.makedirs(os.path.dirname(dist), exist_ok=True)

    entries = collect(SRC)

    for target in (out, dist):
        if os.path.exists(target):
            os.unlink(target)
        with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
            for rel, full in entries:
                zf.write(full, TOP + "/" + rel.replace(os.sep, "/"))

    with zipfile.ZipFile(out) as zf:
        names = zf.namelist()
        header = zf.read(TOP + "/viral-video-ai.php").decode("utf-8")
        readme = zf.read(TOP + "/readme.txt").decode("utf-8")

    ok_version = " * Version:           %s" % version in header
    ok_changelog = "= %s =" % version in readme
    ok_dirs = all(n.startswith(TOP + "/") for n in names)
    size = os.path.getsize(out)

    print("built %s (%d bytes, %d files)" % (out, size, len(names)))
    print("version header: %s | changelog: %s | single top dir: %s" % (ok_version, ok_changelog, ok_dirs))

    missing = [
        "includes/class-binary-locator.php",
        "admin/views/diagnostics.php",
        "tests/tools/php.mjs",
        "tests/harness/mock-ai.cjs",
        "uninstall.php",
    ]
    for rel in missing:
        if TOP + "/" + rel not in names:
            print("MISSING FROM ZIP: %s" % rel, file=sys.stderr)
            return 3

    digest = hashlib.sha256(open(out, "rb").read()).hexdigest()[:16]
    print("sha256[16] %s" % digest)
    return 0 if (ok_version and ok_changelog and ok_dirs) else 4


if __name__ == "__main__":
    sys.exit(main())
