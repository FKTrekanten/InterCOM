#!/usr/bin/env python3
"""Fetch the immutable, checksum-pinned first public package for upgrade CI."""
import hashlib
from pathlib import Path
import urllib.request

version = '0.3.11'
checksum = '127b097f6d5a2986e791b314b96987b6b3223ebdadc69dae0c2c811785114a2c'
target = Path(__file__).resolve().parents[1] / 'dist/upgrade' / f'pkg_intercom-{version}.zip'
if not target.exists() or hashlib.sha256(target.read_bytes()).hexdigest() != checksum:
    url = f'https://github.com/FKTrekanten/InterCOM/releases/download/v{version}/{target.name}'
    with urllib.request.urlopen(url, timeout=60) as response:
        data = response.read()
    if hashlib.sha256(data).hexdigest() != checksum:
        raise SystemExit('Published upgrade fixture checksum mismatch')
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(data)
print(f'Verified upgrade fixture: {target.name}')
