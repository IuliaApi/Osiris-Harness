#!/usr/bin/env python3
"""Cross-format round trip with independent parsers. Requires PHP+DOM, backend/vendor and PyYAML."""
import json
from pathlib import Path
import subprocess
import sys
import xml.etree.ElementTree as ET

try:
    import yaml
except ImportError as error:
    sys.exit('This optional test requires PyYAML (python -m pip install PyYAML).')

ROOT = Path(__file__).resolve().parent

def representation(media):
    return subprocess.check_output(['php', str(ROOT / 'representation-fixture.php'), media], encoding='utf-8')


def xml_to_data(node):
    if node.attrib.get('{http://www.w3.org/2001/XMLSchema-instance}nil') == 'true':
        return None
    kind = node.attrib.get('kind')
    if kind == 'array':
        return [xml_to_data(child) for child in node]
    if kind == 'object':
        return {child.tag: xml_to_data(child) for child in node}
    scalar = node.text or ''
    t = node.attrib.get('type')
    if t == 'boolean':
        return scalar == 'true'
    if t == 'integer':
        return int(scalar)
    if t == 'number':
        return float(scalar)
    return scalar

json_text = representation('application/json')
expected = json.loads(json_text)
yaml_text = representation('application/yaml')
assert yaml.safe_load(yaml_text) == expected
xml_root = ET.fromstring(representation('application/xml'))
assert xml_root.tag == 'response' and xml_to_data(xml_root) == expected
assert expected['empty'] == [] and expected['emptyMap'] == {} and expected['session']['nullable'] is None
assert expected['_links']['update'] == {'href': '/sessions/ses_fixture', 'method': 'PATCH'}

# Input uses the same fields/types, with request instead of response as the XML root.
xml_root.tag = 'request'
for media, body in [('application/json', json_text), ('text/xml', ET.tostring(xml_root, encoding='unicode')),
                    ('text/yaml', yaml_text)]:
    result = subprocess.check_output(['php', str(ROOT / 'representation-fixture.php'), 'decode', media],
                                     input=body, encoding='utf-8')
    assert json.loads(result) == expected, media
print('PASS JSON/XML/YAML input and output preserve lists, maps, scalars and method-aware links')
