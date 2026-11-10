#!/usr/bin/env python3
"""Run the browser-direct split smoke test. Never reads or logs credentials here."""
from pathlib import Path
import json
import os
import subprocess

root = Path(__file__).resolve().parents[2]

def main():
    networks = []
    for name in ('visionsy-split-test-daemon-1', 'visionsy-split-test-dashboard-1'):
        state = json.loads(subprocess.check_output(['docker', 'inspect', name]))[0]
        networks.append(set(state['NetworkSettings']['Networks']))
    assert not networks[0].intersection(networks[1]), 'Containers must not share a Docker network'
    print('PASS: daemon and static dashboard share no Docker network', flush=True)
    subprocess.run(['node', str(root.parent / 'VisionSY_Dashboard/test/direct.browser.mjs')], cwd=root, env=os.environ, check=True)

if __name__ == '__main__':
    main()
