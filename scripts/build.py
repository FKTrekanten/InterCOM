#!/usr/bin/env python3
"""Build only allowlisted extension sources; never package legacy, docs or secrets."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED, ZipInfo
import hashlib, io, xml.etree.ElementTree as ET
ROOT=Path(__file__).resolve().parents[1]
version=(ROOT/'VERSION').read_text().strip()
def archive(files):
    buffer=io.BytesIO()
    with ZipFile(buffer,'w',ZIP_DEFLATED) as z:
        for name,data in sorted(files.items()):
            info=ZipInfo(name,(2026,1,1,0,0,0)); info.compress_type=ZIP_DEFLATED; info.external_attr=0o644<<16
            z.writestr(info,data)
    return buffer.getvalue()
def component(folder):
    files={}
    for p in (ROOT/f'src/{folder}').rglob('*'):
        if p.is_file():
            name=str(p.relative_to(ROOT/f'src/{folder}')); data=p.read_bytes()
            if p.suffix=='.xml':
                tree=ET.fromstring(data)
                if tree.tag=='extension': tree.find('version').text=version; data=ET.tostring(tree,encoding='utf-8',xml_declaration=True)
            files[name]=data
    return archive(files)
manifest=ET.parse(ROOT/'src/pkg_intercom.xml'); manifest.getroot().find('version').text=version
files={'pkg_intercom.xml':ET.tostring(manifest.getroot(),encoding='utf-8',xml_declaration=True),
       'packages/com_intercom.zip':component('component'),'packages/plg_task_intercom.zip':component('task'),'packages/plg_extension_intercom.zip':component('extension')}
out=ROOT/'dist'; out.mkdir(exist_ok=True)
package=out/f'pkg_intercom-{version}.zip'; package.write_bytes(archive(files))
(out/f'{package.name}.sha256').write_text(hashlib.sha256(package.read_bytes()).hexdigest()+'  '+package.name+'\n')
print(package)
