#!/usr/bin/env bash
# Manual GitHub-hosted publisher. Passwords are read from an encrypted Actions secret
# and passed to Subversion through stdin, never through command-line arguments.
set -euo pipefail
export LC_ALL=C

if [[ "${GITHUB_ACTIONS:-}" != true ]]; then
  echo 'Run this publisher through the reviewed manual GitHub workflow.' >&2
  exit 1
fi
version="${WPORG_VERSION:?Missing release version}"
publish="${WPORG_PUBLISH:-false}"
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo 'Invalid version.' >&2; exit 1; }
[[ "$publish" == true || "$publish" == false ]] || { echo 'Invalid publication mode.' >&2; exit 1; }
slug=smartsync-lite-media-offloader-and-assets-cdn
svn_url="https://plugins.svn.wordpress.org/$slug"
release_root="$(mktemp -d "${RUNNER_TEMP:?}/smartsync-wporg.XXXXXXXX")"
build="$release_root/payload"
wc="$release_root/svn"
mkdir "$build"

export WPORG_VERSION
python3 - <<'PY'
import os, re
from pathlib import Path
version = os.environ['WPORG_VERSION']
header = Path('aws-s3-smartsync-wp.php').read_text()
readme = Path('readme.txt').read_text()
assert re.search(r'^\s*\* Version:\s*' + re.escape(version) + r'\s*$', header, re.M)
assert re.search(r"SMARL_VERSION',\s*'" + re.escape(version) + "'", header)
assert re.search(r'^Stable tag:\s*' + re.escape(version) + r'\s*$', readme, re.M)
for forbidden in ['src/Services/SMARLI_GoogleService.php', 'src/Helpers/SMARL_AwsHelper.php', 'src/Views/templates/custom-domain.php', 'vendor/google/cloud-storage']:
    assert not Path(forbidden).exists(), 'Pro implementation in Lite: ' + forbidden
PY

for directory in src assets languages vendor; do
  rsync -a --exclude='.git/' --exclude='.github/' --exclude='node_modules/' --exclude='.DS_Store' --exclude='*.log' "$directory/" "$build/$directory/"
done
for file in aws-s3-smartsync-wp.php uninstall.php composer.json composer.lock readme.txt; do
  cp "$file" "$build/$file"
done
[[ -f "$build/vendor/autoload.php" && -f "$build/vendor/woocommerce/action-scheduler/action-scheduler.php" ]]
[[ ! -d "$build/Documentation" && ! -d "$build/tests" && ! -d "$build/.git" ]]

svn checkout --depth immediates --non-interactive --no-auth-cache "$svn_url" "$wc"
svn update --set-depth infinity --non-interactive --no-auth-cache "$wc/trunk"
svn list --non-interactive --no-auth-cache "$svn_url/tags/" > "$release_root/tags.txt"
if grep -Fxq "$version/" "$release_root/tags.txt"; then
  echo "Version $version already exists. Published tags must not be overwritten." >&2
  exit 1
fi
previous="$(svn cat --non-interactive --no-auth-cache "$svn_url/trunk/readme.txt" | sed -n 's/^Stable tag:[[:space:]]*//p' | tr -d '\r' | head -n 1)"
[[ "$previous" =~ ^[0-9]+(\.[0-9]+)+$ ]] || { echo 'Previous stable tag is not a numeric release.' >&2; exit 1; }
rsync -rc --delete "$build/" "$wc/trunk/"
export WPORG_INTERIM_README="$wc/trunk/readme.txt" WPORG_PREVIOUS="$previous"
python3 - <<'PY'
import os, re
from pathlib import Path
p = Path(os.environ['WPORG_INTERIM_README'])
p.write_text(re.sub(r'^Stable tag:.*$', 'Stable tag: ' + os.environ['WPORG_PREVIOUS'], p.read_text(), count=1, flags=re.M))
PY

svn add --force "$wc/trunk" > /dev/null
svn status --xml "$wc/trunk" > "$release_root/status.xml"
python3 - "$release_root/status.xml" "$wc/trunk" <<'PY'
import subprocess, sys
from pathlib import Path
from xml.etree import ElementTree as ET
boundary = Path(sys.argv[2]).resolve()
for entry in ET.parse(sys.argv[1]).iter('entry'):
    path = Path(entry.attrib['path']).resolve()
    assert path == boundary or boundary in path.parents, 'Path escaped release trunk'
    if entry.find('wc-status').attrib['item'] == 'missing':
        subprocess.run(['svn', 'delete', '--force', str(path)], check=True, stdout=subprocess.DEVNULL)
PY
svn diff --summarize "$wc/trunk"
echo "Prepared SmartSync Lite $version; current stable release is $previous."
if [[ "$publish" == false ]]; then
  echo 'Preview complete. No WordPress.org write was performed.'
  exit 0
fi
[[ "${SVN_USERNAME:-}" == infinitietech && -n "${SVN_PASSWORD:-}" ]] || { echo 'The approved WordPress.org publishing credential is missing.' >&2; exit 1; }
svn update --non-interactive --no-auth-cache "$wc/trunk"
if [[ -n "$(svn status "$wc/trunk")" ]]; then
  printf '%s\n' "$SVN_PASSWORD" | svn commit "$wc/trunk" --username "$SVN_USERNAME" --password-from-stdin --no-auth-cache --non-interactive -m "Stage SmartSync Lite $version; retain stable release $previous." | tee "$release_root/stage-commit.txt"
  revision="$(sed -n 's/^Committed revision \([0-9][0-9]*\)\.$/\1/p' "$release_root/stage-commit.txt")"
else
  revision="$(svn info --show-item revision --non-interactive --no-auth-cache "$wc/trunk")"
fi
[[ "$revision" =~ ^[0-9]+$ ]]
printf '%s\n' cp "$revision" trunk "tags/$version" put "$build/readme.txt" trunk/readme.txt put "$build/readme.txt" "tags/$version/readme.txt" > "$release_root/final-actions.txt"
printf '%s\n' "$SVN_PASSWORD" | svnmucc --root-url "$svn_url" --revision "$revision" --username "$SVN_USERNAME" --password-from-stdin --no-auth-cache --non-interactive --extra-args "$release_root/final-actions.txt" -m "Release SmartSync Lite $version."
unset SVN_PASSWORD
svn info --non-interactive --no-auth-cache "$svn_url/tags/$version"
echo "SVN release $version committed. Verify WordPress.org processing and any required release-confirmation email."
