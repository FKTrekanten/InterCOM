#!/usr/bin/env python3
"""Test and prepare a reviewable release; --publish explicitly publishes it."""
import argparse
import hashlib
import json
import re
import subprocess
import sys
import tempfile
import time
import urllib.request
import xml.etree.ElementTree as ET
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPO = 'FKTrekanten/InterCOM'
GENERATED = ['VERSION', 'CHANGELOG.md', 'src/component/intercom.xml',
             'src/task/intercom.xml', 'src/pkg_intercom.xml',
             'src/component/media/joomla.asset.json', 'src/extension/intercom.xml']
STATE = ROOT / 'dist/release-state.json'

def run(*args, capture=False):
    return subprocess.run(args, cwd=ROOT, check=True, text=True,
                          stdout=subprocess.PIPE if capture else None).stdout

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def make_feed(version, checksum):
    updates = ET.Element('updates')
    update = ET.SubElement(updates, 'update')
    fields = {'name':'InterCOM', 'description':'Member communications',
              'element':'pkg_intercom', 'type':'package', 'version':version, 'client':'0',
              'infourl':f'https://github.com/{REPO}/releases/tag/v{version}'}
    for key, value in fields.items():
        ET.SubElement(update, key).text = value
    ET.SubElement(ET.SubElement(update, 'downloads'), 'downloadurl',
                  {'type':'full', 'format':'zip'}).text = (
        f'https://github.com/{REPO}/releases/download/v{version}/pkg_intercom-{version}.zip')
    ET.SubElement(update, 'targetplatform', {'name':'joomla', 'version':r'6\.[1-9][0-9]*'})
    ET.SubElement(update, 'php_minimum').text = '8.3'
    ET.SubElement(update, 'sha256').text = checksum
    return ET.tostring(updates, encoding='utf-8', xml_declaration=True)

def prepare(version, notes):
    run('bash', 'scripts/ci.sh')
    (ROOT / 'VERSION').write_text(version + '\n')
    release_date = date.today().isoformat()
    for name in GENERATED[2:5] + [GENERATED[6]]:
        path = ROOT / name
        tree = ET.parse(path)
        tree.getroot().find('version').text = version
        created = tree.getroot().find('creationDate')
        if created is None:
            created = ET.SubElement(tree.getroot(), 'creationDate')
        created.text = release_date
        tree.write(path, encoding='utf-8', xml_declaration=True)
    asset = ROOT / GENERATED[5]
    data = json.loads(asset.read_text())
    data['version'] = version
    asset.write_text(json.dumps(data, indent=2) + '\n')
    changelog = ROOT / 'CHANGELOG.md'
    old = changelog.read_text() if changelog.exists() else '# Changelog\n'
    if re.search(r'^## ' + re.escape(version) + r'(?:\s|$)', old, re.M):
        sys.exit('This version already has a changelog entry; choose a new version.')
    changelog.write_text('# Changelog\n\n## ' + version + '\n\n' + notes + '\n\n'
                         + old.removeprefix('# Changelog').lstrip())

def verify_public(url, expected_hash):
    # Retry boundedly for GitHub publication/cache propagation; never skip validation.
    for attempt in range(12):
        try:
            with urllib.request.urlopen(url, timeout=30) as response:
                if hashlib.sha256(response.read()).hexdigest() == expected_hash:
                    return
        except (OSError, ValueError):
            pass
        time.sleep(5)
    sys.exit('Public URL verification failed: ' + url)

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('version')
    parser.add_argument('--notes', required=True, type=Path)
    parser.add_argument('--publish', action='store_true')
    args = parser.parse_args()
    version = args.version
    if not re.fullmatch(r'(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)', version):
        sys.exit('Use a stable semantic version, e.g. 0.1.0')
    notes_path = args.notes.resolve()
    notes = notes_path.read_text().strip()
    if not notes:
        sys.exit('Release notes must not be empty')
    if run('git', 'branch', '--show-current', capture=True).strip() != 'main':
        sys.exit('Releases run only on main')
    origin = run('git', 'remote', 'get-url', 'origin', capture=True).strip()
    if origin not in [f'https://github.com/{REPO}.git', f'git@github.com:{REPO}.git']:
        sys.exit('Unexpected origin; refusing to publish to a different repository')
    run('git', 'fetch', 'origin', 'main', '--tags')
    head = run('git', 'rev-parse', 'HEAD', capture=True).strip()
    if head != run('git', 'rev-parse', 'origin/main', capture=True).strip():
        sys.exit('Local main must match origin/main')
    if subprocess.run(['git', 'rev-parse', '--verify', 'refs/tags/v' + version], cwd=ROOT,
                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
        sys.exit('Version tag already exists. Never overwrite releases; see recovery instructions.')
    current = tuple(map(int, (ROOT / 'VERSION').read_text().strip().split('.')))
    if tuple(map(int, version.split('.'))) < current:
        sys.exit('Release versions must not go backwards')
    status = run('git', 'status', '--porcelain', capture=True)
    resume = json.loads(STATE.read_text()) if STATE.exists() else {}
    valid_resume = (resume.get('version') == version and resume.get('head') == head
                    and resume.get('notes_hash') == hashlib.sha256(notes.encode()).hexdigest()
                    and resume.get('files') == {name:digest(ROOT / name) for name in GENERATED})
    if status and not valid_resume:
        sys.exit('Commit reviewed work on main first. Only unchanged release preparation can be resumed.')
    if status and any(line[3:] not in GENERATED for line in status.splitlines()):
        sys.exit('Unrelated changes found; refusing to include them in a release')
    if not valid_resume:
        if re.search(r'^## ' + re.escape(version) + r'(?:\s|$)', (ROOT/'CHANGELOG.md').read_text(), re.M):
            sys.exit('Version already appears in changelog; choose a new version')
        prepare(version, notes)
    # Always rerun CI on the exact sources being released, including on resume.
    run('bash', 'scripts/ci.sh')
    package = ROOT / 'dist' / f'pkg_intercom-{version}.zip'
    checksum = digest(package)
    feed = make_feed(version, checksum)
    (ROOT / 'dist/intercom.xml').write_bytes(feed)
    STATE.write_text(json.dumps({'head':head, 'version':version,
        'notes_hash':hashlib.sha256(notes.encode()).hexdigest(),
        'files':{name:digest(ROOT / name) for name in GENERATED}}, indent=2) + '\n')
    if not args.publish:
        print('PREPARED, NOT PUBLISHED. Review git diff, then rerun the same command with --publish.')
        return
    run('gh', 'auth', 'status')
    run('git', 'add', *GENERATED)
    run('git', 'commit', '-m', f'Release {version}')
    release_commit = run('git', 'rev-parse', 'HEAD', capture=True).strip()
    run('git', 'tag', '-a', 'v'+version, '-m', f'InterCOM {version}')
    run('git', 'push', '--atomic', 'origin', 'main', 'v'+version)
    run('gh', 'release', 'create', 'v'+version, str(package), str(package)+'.sha256',
        '--repo', REPO, '--verify-tag', '--draft', '--title', f'InterCOM {version}',
        '--notes-file', str(notes_path))
    run_id = None
    for _ in range(40):
        runs = json.loads(run('gh', 'run', 'list', '--repo', REPO, '--workflow', 'ci.yml',
            '--commit', release_commit, '--json', 'databaseId', '--limit', '1', capture=True))
        if runs:
            run_id = str(runs[0]['databaseId'])
            break
        time.sleep(3)
    if not run_id:
        sys.exit('Actions run not found. Release remains draft; update feed unchanged.')
    run('gh', 'run', 'watch', run_id, '--repo', REPO, '--exit-status', '--interval', '10')
    with tempfile.TemporaryDirectory() as directory:
        run('gh', 'release', 'download', 'v'+version, '--repo', REPO, '--pattern', package.name,
            '--dir', directory)
        if digest(Path(directory) / package.name) != checksum:
            sys.exit('Uploaded asset checksum mismatch; release remains draft')
    run('gh', 'release', 'edit', 'v'+version, '--repo', REPO, '--draft=false')
    verify_public(f'https://github.com/{REPO}/releases/download/v{version}/{package.name}', checksum)
    (ROOT / 'updates/intercom.xml').write_bytes(feed)
    run('git', 'add', 'updates/intercom.xml')
    run('git', 'commit', '-m', f'Publish update feed for {version}')
    run('git', 'push', 'origin', 'main')
    feed_commit = run('git', 'rev-parse', 'HEAD', capture=True).strip()
    verify_public(f'https://raw.githubusercontent.com/{REPO}/{feed_commit}/updates/intercom.xml',
                  hashlib.sha256(feed).hexdigest())
    verify_public(f'https://raw.githubusercontent.com/{REPO}/main/updates/intercom.xml',
                  hashlib.sha256(feed).hexdigest())
    STATE.unlink(missing_ok=True)
    print(f'VERIFIED RELEASE: https://github.com/{REPO}/releases/tag/v{version}')

if __name__ == '__main__':
    try:
        main()
    except (subprocess.CalledProcessError, OSError) as error:
        sys.exit(f'Release stopped: {error}. No automatic rollback or tag replacement was attempted.')
