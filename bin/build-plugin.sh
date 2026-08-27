#!/usr/bin/env bash

set -euo pipefail

# ZIP stores local timestamps, so pin the conversion independently of the runner.
export TZ=UTC
export LC_ALL=C

plugin_slug="darven-who-published"
plugin_version="1.1.0"
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
project_root=$(cd "$script_dir/.." && pwd)
archive_path="$project_root/build/$plugin_slug.$plugin_version.zip"
build_stage_dir=""
output_stage_dir=""
build_succeeded=0

# Canonical outputs must never survive or appear during a failed rebuild.
mkdir -p "$project_root/build"
rm -rf "$project_root/build/plugin"
rm -f "$archive_path"

cleanup() {
	if [ -n "$build_stage_dir" ]; then
		rm -rf "$build_stage_dir"
	fi
	if [ -n "$output_stage_dir" ]; then
		rm -rf "$output_stage_dir"
	fi
	if [ "$build_succeeded" -ne 1 ]; then
		rm -rf "$project_root/build/plugin"
		rm -f "$archive_path"
	fi
}
trap cleanup EXIT INT TERM

for command_name in chmod composer cp find git sort touch zip; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "Build failed: required command '$command_name' is unavailable." >&2
		exit 1
	fi
done

source_date_epoch=${SOURCE_DATE_EPOCH:-}
if [ -z "$source_date_epoch" ] && command -v git >/dev/null 2>&1; then
	source_date_epoch=$(git -C "$project_root" log -1 --format=%ct 2>/dev/null || true)
fi
if ! [[ "$source_date_epoch" =~ ^[0-9]+$ ]]; then
	echo "Build failed: SOURCE_DATE_EPOCH must be a non-negative integer." >&2
	exit 1
fi

if ! git -C "$project_root" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
	echo "Build failed: production packages must be built from a Git worktree." >&2
	exit 1
fi

path_is_unsafe() {
	local relative_path=$1
	if [[ "$relative_path" =~ [[:cntrl:]] ]]; then
		return 0
	fi
	case "/$relative_path" in
		*/../*|*/..|*/./*|*/.|*//*|*\\*)
			return 0
			;;
	esac
	return 1
}

# Inspect names and link types only; find does not follow symlinks by default.
while IFS= read -r -d '' source_path; do
	relative_path=${source_path#"$project_root"/}
	if path_is_unsafe "$relative_path"; then
		echo "Build failed: source path is not safe to archive: $relative_path" >&2
		exit 1
	fi
	if [ -L "$source_path" ]; then
		echo "Build failed: source symlinks are not allowed: $relative_path" >&2
		exit 1
	fi
done < <(
	find "$project_root" \
		\( -path "$project_root/.git" -o -path "$project_root/build" -o -path "$project_root/vendor" \) -prune \
		-o -mindepth 1 -print0
)

build_stage_dir=$(mktemp -d "/tmp/who-published-build-XXXXXX")
output_stage_dir=$(mktemp -d "$project_root/build/.who-published-output-XXXXXX")
stage_plugin="$build_stage_dir/$plugin_slug"
mkdir -p "$stage_plugin"

# Only tracked files in the explicit production roots may enter the package.
tracked_file_count=0
while IFS= read -r -d '' index_record; do
	index_metadata=${index_record%%$'\t'*}
	relative_path=${index_record#*$'\t'}
	read -r git_mode _ git_stage <<< "$index_metadata"

	if [ "$relative_path" = "$index_record" ] || path_is_unsafe "$relative_path"; then
		echo "Build failed: tracked path is not safe to archive: $relative_path" >&2
		exit 1
	fi
	if [ "$git_stage" != "0" ]; then
		echo "Build failed: unmerged index entry is not releasable: $relative_path" >&2
		exit 1
	fi
	if [ "$git_mode" = "120000" ] || [ -L "$project_root/$relative_path" ]; then
		echo "Build failed: tracked symlinks are not allowed: $relative_path" >&2
		exit 1
	fi
	case "$git_mode" in
		100644|100755)
			;;
		*)
			echo "Build failed: unsupported Git mode $git_mode: $relative_path" >&2
			exit 1
			;;
	esac
	if [ ! -f "$project_root/$relative_path" ]; then
		echo "Build failed: tracked production file is missing: $relative_path" >&2
		exit 1
	fi

	mkdir -p "$stage_plugin/$(dirname "$relative_path")"
	cp --no-dereference --preserve=mode,timestamps \
		"$project_root/$relative_path" \
		"$stage_plugin/$relative_path"
	tracked_file_count=$(( tracked_file_count + 1 ))
done < <(
	git -C "$project_root" ls-files --stage -z -- \
		darven-who-published.php \
		readme.txt \
		assets \
		languages \
		src
)

if [ "$tracked_file_count" -eq 0 ]; then
	echo "Build failed: the tracked production allowlist is empty." >&2
	exit 1
fi

for build_input in composer.json composer.lock; do
	if ! git -C "$project_root" ls-files --error-unmatch -- "$build_input" >/dev/null 2>&1; then
		echo "Build failed: required tracked build input is missing: $build_input" >&2
		exit 1
	fi
done

# Composer's manifests generate the production autoloader. Keep composer.json
# in the package so consumers and Plugin Check can identify the vendor tree;
# composer.lock remains a development-only build input.
cp "$project_root/composer.json" "$project_root/composer.lock" "$stage_plugin/"
COMPOSER_ROOT_VERSION="$plugin_version" composer install \
	--working-dir="$stage_plugin" \
	--no-dev \
	--classmap-authoritative \
	--no-interaction \
	--no-progress \
	--prefer-dist
rm "$stage_plugin/composer.lock"

# Composer output is untrusted until it passes the same path/link boundary.
while IFS= read -r -d '' staged_path; do
	staged_relative_path=${staged_path#"$stage_plugin"/}
	if path_is_unsafe "$staged_relative_path" || [ -L "$staged_path" ]; then
		echo "Build failed: generated path is not safe to archive: $staged_relative_path" >&2
		exit 1
	fi
done < <(find "$stage_plugin" -mindepth 1 -print0)

# Normalize permissions and timestamps before the inspectable tree and archive.
find "$stage_plugin" -type d -exec chmod 0755 {} +
find "$stage_plugin" -type f -exec chmod 0644 {} +
find "$stage_plugin" -exec touch -h -d "@$source_date_epoch" {} +

if find "$stage_plugin" -name $'*\n*' -print -quit | grep -q .; then
	echo "Build failed: source paths containing newlines cannot be archived safely." >&2
	exit 1
fi

(
	cd "$build_stage_dir"
	find "$plugin_slug" -print | sort | zip -X -q "$output_stage_dir/$plugin_slug.$plugin_version.zip" -@
)
bash "$script_dir/verify-build.sh" "$output_stage_dir/$plugin_slug.$plugin_version.zip" >/dev/null

mkdir -p "$output_stage_dir/plugin"
cp -a "$stage_plugin" "$output_stage_dir/plugin/$plugin_slug"
mv "$output_stage_dir/plugin" "$project_root/build/plugin"
mv "$output_stage_dir/$plugin_slug.$plugin_version.zip" "$archive_path"
build_succeeded=1

echo "Built $archive_path"
