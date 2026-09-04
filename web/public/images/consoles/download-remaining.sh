#!/usr/bin/env bash
# Run this after the GitHub API rate limit resets (~1 hour)
# Optionally set GITHUB_TOKEN for higher limits:
#   GITHUB_TOKEN=ghp_xxx ./download-remaining.sh

set -e

DEST="$(dirname "$0")"

python3 -c "
import sys, json, urllib.request, urllib.parse, os

dest = '$DEST'
existing = set(os.listdir(dest))

token = os.environ.get('GITHUB_TOKEN', '')
headers = {'Accept': 'application/vnd.github.raw'}
if token:
    headers['Authorization'] = f'Bearer {token}'

req = urllib.request.Request(
    'https://api.github.com/repos/libretro/retroarch-assets/contents/xmb/retrosystem/png',
    headers={'Accept': 'application/vnd.github+json', 'Authorization': f'Bearer {token}'} if token else {}
)
with urllib.request.urlopen(req) as r:
    data = json.load(r)

missing = [f for f in data if f['name'] not in existing]
print(f'Downloading {len(missing)} remaining files...')

for i, f in enumerate(missing, 1):
    name = f['name']
    try:
        req = urllib.request.Request(f['url'], headers=headers)
        with urllib.request.urlopen(req) as resp:
            with open(os.path.join(dest, name), 'wb') as out:
                out.write(resp.read())
        print(f'[{i}/{len(missing)}] {name}')
    except Exception as e:
        print(f'[{i}/{len(missing)}] FAILED {name}: {e}')

print('Done.')
"
