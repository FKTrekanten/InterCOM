import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
import xml.etree.ElementTree as ET

spec = importlib.util.spec_from_file_location('release', Path(__file__).parents[2] / 'scripts/release.py')
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)

class ReleaseTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        for name in release.GENERATED:
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('<extension><version>0.1.0</version></extension>')
        (self.root/'VERSION').write_text('0.1.0\n')
        (self.root/'CHANGELOG.md').write_text('# Changelog\n\n## Unreleased\n')
        (self.root/release.GENERATED[5]).write_text('{"version":"0.1.0"}')
        (self.root/'notes.md').write_text('Tested release notes.')
        (self.root/'dist').mkdir()
        self.calls = []
        self.status = ''
        self.ci_failure = False
        for target, value in [('ROOT',self.root),('STATE',self.root/'dist/release-state.json')]:
            patcher = patch.object(release,target,value)
            patcher.start()
            self.addCleanup(patcher.stop)

    def command(self, *args, capture=False):
        self.calls.append(args)
        values = {('git','branch','--show-current'):'main',
                  ('git','remote','get-url','origin'):'https://github.com/FKTrekanten/InterCOM.git',
                  ('git','rev-parse','HEAD'):'abc', ('git','rev-parse','origin/main'):'abc',
                  ('git','status','--porcelain'):self.status}
        if args == ('bash','scripts/ci.sh'):
            if self.ci_failure:
                raise subprocess.CalledProcessError(1,args)
            version = (self.root/'VERSION').read_text().strip()
            (self.root/f'dist/pkg_intercom-{version}.zip').write_bytes(b'tested-package')
        return values.get(args,'')

    def invoke(self, publish=False):
        argv = ['release.py','0.2.0','--notes',str(self.root/'notes.md')]
        if publish: argv.append('--publish')
        with patch.object(release.sys,'argv',argv), patch.object(release,'run',self.command), \
             patch.object(release.subprocess,'run',return_value=subprocess.CompletedProcess([],1)):
            release.main()

    def test_preparation_can_resume_without_duplicate_changelog(self):
        self.invoke()
        self.assertEqual(self.calls.count(('bash','scripts/ci.sh')),2)
        self.assertFalse(any(call[:2] == ('git','push') for call in self.calls))
        self.status = ' M VERSION\n M CHANGELOG.md\n'
        self.calls.clear()
        self.invoke()
        self.assertEqual(self.calls.count(('bash','scripts/ci.sh')),1)
        self.assertEqual((self.root/'CHANGELOG.md').read_text().count('## 0.2.0'),1)

    def test_modified_preparation_cannot_publish(self):
        self.invoke()
        (self.root/'VERSION').write_text('0.3.0\n')
        self.status = ' M VERSION\n'
        self.calls.clear()
        with self.assertRaises(SystemExit): self.invoke(publish=True)
        self.assertFalse(any(call[:2] == ('git','push') for call in self.calls))

    def test_ci_failure_stops_before_version_change_or_publication(self):
        self.ci_failure = True
        with self.assertRaises(subprocess.CalledProcessError): self.invoke(publish=True)
        self.assertEqual((self.root/'VERSION').read_text(),'0.1.0\n')
        self.assertFalse(any(call[:2] == ('git','push') for call in self.calls))

    def test_feed_identifies_package_checksum_and_runtime(self):
        update = ET.fromstring(release.make_feed('0.2.0','a'*64)).find('update')
        self.assertEqual(update.findtext('element'),'pkg_intercom')
        self.assertEqual(update.findtext('sha256'),'a'*64)
        self.assertEqual(update.findtext('php_minimum'),'8.3')
        self.assertTrue(update.findtext('downloads/downloadurl').endswith('/v0.2.0/pkg_intercom-0.2.0.zip'))

if __name__ == '__main__': unittest.main()
