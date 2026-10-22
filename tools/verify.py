#!/usr/bin/env python3
"""Lint PHP and verify XML syntax. Run Moodle PHPUnit separately for functional tests."""
import argparse
import subprocess
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("--php", default="php")
args = parser.parse_args()
repo = Path(__file__).resolve().parents[1]
files = sorted(repo.glob("local/**/*.php")) + sorted(repo.glob("quizaccess/**/*.php"))
failures = []
for path in files:
    result = subprocess.run([args.php, "-l", str(path)], capture_output=True, text=True)
    if result.returncode:
        failures.append(result.stdout + result.stderr)
ET.parse(repo / "local/examsync/db/install.xml")
print(f"PHP syntax: {len(files)} files; {len(failures)} failures. XML parsed.")
for failure in failures:
    print(failure)
sys.exit(bool(failures))
