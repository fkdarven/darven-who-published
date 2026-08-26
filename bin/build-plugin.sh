#!/usr/bin/env bash

set -euo pipefail

# ZIP stores local timestamps, so pin the conversion independently of the runner.
export TZ=UTC

plugin_slug="darven-who-published"
plugin_version="1.1.0"
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
project_root=$(cd "$script_dir/.." && pwd)
archive_path="$project_root/build/$plugin_slug.$plugin_version.zip"
plugin_tree="$project_root/build/plugin/$plugin_slug"
build_stage_dir=$(mktemp -d "/tmp/who-published-build-XXXXXX")

cleanup() {
	rm -rf "$build_stage_dir"
}
trap cleanup EXIT INT TERM

for command_name in chmod composer find rsync sort touch zip; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "Build failed: required command '$command_name' is unavailable." >&2
		exit 1
	fi
done

if [ ! -f "$project_root/.distignore" ]; then
	echo "Build failed: $project_root/.distignore does not exist." >&2
	exit 1
fi

source_date_epoch=${SOURCE_DATE_EPOCH:-}
if [ -z "$source_date_epoch" ] && command -v git >/dev/null 2>&1; then
	source_date_epoch=$(git -C "$project_root" log -1 --format=%ct 2>/dev/null || true)
fi
if ! [[ "$source_date_epoch" =~ ^[0-9]+$ ]]; then
	echo "Build failed: SOURCE_DATE_EPOCH must be a non-negative integer." >&2
	exit 1
fi

stage_plugin="$build_stage_dir/$plugin_slug"
mkdir -p "$stage_plugin" "$project_root/build"

rsync -a \
	--exclude-from="$project_root/.distignore" \
	"$project_root/" \
	"$stage_plugin/"

# Composer's manifests are needed only while generating the production autoloader.
cp "$project_root/composer.json" "$project_root/composer.lock" "$stage_plugin/"
COMPOSER_ROOT_VERSION="$plugin_version" composer install \
	--working-dir="$stage_plugin" \
	--no-dev \
	--classmap-authoritative \
	--no-interaction \
	--no-progress \
	--prefer-dist
rm "$stage_plugin/composer.json" "$stage_plugin/composer.lock"

# Normalize permissions and timestamps before the inspectable tree and archive.
find "$stage_plugin" -type d -exec chmod 0755 {} +
find "$stage_plugin" -type f -exec chmod 0644 {} +
find "$stage_plugin" -exec touch -h -d "@$source_date_epoch" {} +

if find "$stage_plugin" -name $'*\n*' -print -quit | grep -q .; then
	echo "Build failed: source paths containing newlines cannot be archived safely." >&2
	exit 1
fi

rm -rf "$project_root/build/plugin"
mkdir -p "$(dirname "$plugin_tree")"
cp -a "$stage_plugin" "$plugin_tree"

rm -f "$archive_path"
(
	cd "$build_stage_dir"
	LC_ALL=C find "$plugin_slug" -print | LC_ALL=C sort | zip -X -q "$archive_path" -@
)

echo "Built $archive_path"
