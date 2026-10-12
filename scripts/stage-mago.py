#!/usr/bin/env python3
"""Stage all official Mago binaries before building Pint's single PHAR."""
import hashlib
import io
import json
from pathlib import Path
import subprocess
import tarfile
import tempfile
import zipfile

VERSION = "1.56.0"
# SHA256 digests published by the official GitHub release API, not fetched at build time.
ASSETS = {
    "linux-x64": ("x86_64-unknown-linux-musl.tar.gz", "d7708fd5041943236fa4802cc4a4428b59f150bcb85962d3d1c3f1a0049c2e71"),
    "linux-arm64": ("aarch64-unknown-linux-musl.tar.gz", "df1ba9acbd914c261efa966f7cc6e35c53a2c0c5112deddd17266182a6b8a6ff"),
    "darwin-x64": ("x86_64-apple-darwin.tar.gz", "80d823cd6cd77df360ca21153e774cf34942ec15ef87eb3dab17bf7c4387e779"),
    "darwin-arm64": ("aarch64-apple-darwin.tar.gz", "f6131a9979d1cb1fff0a650691587180c03ffc9f22bd14bb227416673a303151"),
    "windows-x64": ("x86_64-pc-windows-msvc.zip", "d3053551a307f190dbe44446e825cd068b3ffc3888ed2227cf88ccc32b71602f"),
}


def main():
    root = Path(__file__).resolve().parents[1] / "resources/mago"
    manifest = {"version": VERSION, "platforms": {}}
    with tempfile.TemporaryDirectory(prefix="pint-mago-build-") as temporary:
        for platform, (suffix, digest) in ASSETS.items():
            name = f"mago-{VERSION}-{suffix}"
            archive = Path(temporary) / name
            url = f"https://github.com/carthage-software/mago/releases/download/{VERSION}/{name}"
            subprocess.run(["curl", "--fail", "--location", "--retry", "3", "--silent", "--show-error", "--output", str(archive), url], check=True)
            data = archive.read_bytes()
            if hashlib.sha256(data).hexdigest() != digest:
                raise RuntimeError(f"Archive checksum mismatch: {name}")
            if suffix.endswith(".zip"):
                with zipfile.ZipFile(io.BytesIO(data)) as package:
                    members = {Path(n).name: package.read(n) for n in package.namelist() if Path(n).name in ("mago.exe", "LICENSE-MIT", "LICENSE-APACHE")}
            else:
                with tarfile.open(fileobj=io.BytesIO(data), mode="r:gz") as package:
                    members = {Path(n.name).name: package.extractfile(n).read() for n in package.getmembers() if n.isfile() and Path(n.name).name in ("mago", "LICENSE-MIT", "LICENSE-APACHE")}
            executable = "mago.exe" if platform.startswith("windows") else "mago"
            destination = root / platform
            destination.mkdir(parents=True, exist_ok=True)
            for member in (executable, "LICENSE-MIT", "LICENSE-APACHE"):
                target = destination / member
                target.write_bytes(members[member])
                target.chmod(0o755 if member == executable else 0o644)
            manifest["platforms"][platform] = {
                "file": f"{platform}/{executable}",
                "sha256": hashlib.sha256(members[executable]).hexdigest(),
                "archive": name,
                "archive_sha256": digest,
            }
            print(f"Staged {platform}: {len(members[executable]):,} bytes")
    (root / "manifest.json").write_text(json.dumps(manifest, indent=4) + "\n")


if __name__ == "__main__":
    main()
