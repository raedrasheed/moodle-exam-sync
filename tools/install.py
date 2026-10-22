#!/usr/bin/env python3
"""Install only the two ExamSync plugin directories into an existing Moodle checkout."""
import argparse
import shutil
import sys
from datetime import datetime, timezone
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("moodle", type=Path, help="Moodle root (parent of public for Moodle 5.1)")
parser.add_argument("--update", action="store_true", help="Back up existing ExamSync directories before replacing")
args = parser.parse_args()
repo = Path(__file__).resolve().parents[1]
root = args.moodle.resolve()
web = root / "public" if (root / "public/version.php").is_file() else root
if not (web / "version.php").is_file() or not (web / "mod/quiz").is_dir():
    parser.error("The destination is not a Moodle source tree")
version = (web / "version.php").read_text()
if "'501'" not in version:
    parser.error("This release targets Moodle 5.1 only")
destinations = [(repo / "local/examsync", web / "local/examsync"),
                (repo / "quizaccess/examsync", web / "mod/quiz/accessrule/examsync")]
for source, dest in destinations:
    if dest.exists() and not args.update:
        parser.error(f"{dest} exists; use --update to preserve a backup and replace it")
stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
backup = root.parent / f"examsync-plugin-backup-{stamp}"
for source, dest in destinations:
    if dest.exists():
        saved = backup / dest.relative_to(web)
        saved.parent.mkdir(parents=True, exist_ok=True)
        shutil.copytree(dest, saved)
        if dest.is_symlink():
            dest.unlink()
        else:
            shutil.rmtree(dest)
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.copytree(source, dest, dirs_exist_ok=True)
    print(f"Installed {dest}")
if backup.exists():
    print(f"Previous plugin copies: {backup}")
print(f"Next: php {web / 'admin/cli/upgrade.php'} --non-interactive")
print("Then configure the site role and LTI registration; see docs/LOCAL-LAB.ar.md")
