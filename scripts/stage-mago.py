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

VERSION = "1.53.0"
# SHA256 digests published by the official GitHub release API, not fetched at build time.
ASSETS = {
    "linux-x64": ("x86_64-unknown-linux-musl.tar.gz", "55e27c547fd1436fa565880f4eb5cb8cc49279170df3996e739942a2639544c9"),
    "linux-arm64": ("aarch64-unknown-linux-musl.tar.gz", "737e67b276c12bdd2527063302ca7f65bb79592daf9d45d7e2baaf6dbcf210e3"),
    "darwin-x64": ("x86_64-apple-darwin.tar.gz", "c20b746e0fdc0ad88e0836689561e387ea86f18fad603e584f85239f56ecab5c"),
    "darwin-arm64": ("aarch64-apple-darwin.tar.gz", "bb228955996f04f58b980feb048e2947a300116982acc7b69621cc43bf5f9dd7"),
    "windows-x64": ("x86_64-pc-windows-msvc.zip", "d5841d0dc305d732b439f47549f137fef02a29d3b65ed6fe1dd37c21a9b5a133"),
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
