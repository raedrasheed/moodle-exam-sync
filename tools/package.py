#!/usr/bin/env python3
"""Build Moodle plugin ZIPs and a source bundle without credentials or development dependencies."""
import hashlib
import zipfile
from pathlib import Path

repo = Path(__file__).resolve().parents[1]
dist = repo / "dist"
dist.mkdir(exist_ok=True)
archives = []
for folder, name in [("local/examsync", "local_examsync"), ("quizaccess/examsync", "quizaccess_examsync")]:
    archive = dist / f"{name}-0.1.0-alpha.zip"
    root = repo / folder
    with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as z:
        for path in sorted(root.rglob("*")):
            if path.is_file() and "__pycache__" not in path.parts:
                z.write(path, Path("examsync") / path.relative_to(root))
    archives.append(archive)
archive = dist / "moodle-exam-sync-0.1.0-alpha.zip"
with zipfile.ZipFile(archive, "w", zipfile.ZIP_DEFLATED) as z:
    for path in sorted(repo.rglob("*")):
        relative = path.relative_to(repo)
        if not path.is_file() or any(p in {".git", "dist", "__pycache__", "vendor", "node_modules"} for p in relative.parts):
            continue
        if relative.parts[0] not in {"local", "quizaccess", "tools", "docs", "examples", ".github"} and relative.name not in {"README.md", "LICENSE", ".gitignore", ".gitattributes"}:
            continue
        z.write(path, Path("moodle-exam-sync") / relative)
archives.append(archive)
(dist / "SHA256SUMS").write_text("".join(f"{hashlib.sha256(a.read_bytes()).hexdigest()}  {a.name}\n" for a in archives))
for archive in archives:
    print(archive)
