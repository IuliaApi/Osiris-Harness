#!/usr/bin/env python3
"""Optional cross-format data consistency check; requires PHP CLI and `pip install PyYAML`."""
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
    return subprocess.check_output(['php', str(ROOT / 'representation-fixture.php'), media], text=True)


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

expected = json.loads(representation('application/json'))
from_yaml = yaml.safe_load(representation('application/yaml'))
from_xml = xml_to_data(ET.fromstring(representation('application/xml')))
assert expected == from_yaml, f'YAML differs: {from_yaml!r}'
assert expected == from_xml, f'XML differs: {from_xml!r}'
assert expected['empty'] == [] and expected['session']['nullable'] is None
print('PASS JSON, XML and YAML parse to the same nested values, lists, nulls, booleans and numbers')
