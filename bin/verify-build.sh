#!/usr/bin/env bash

set -euo pipefail

plugin_slug="darven-who-published"
archive_path=${1:-}

fail() {
	echo "Build verification failed: $1" >&2
	exit 1
}

if [ -z "$archive_path" ]; then
	fail "usage: $0 <plugin-zip>"
fi

for command_name in grep sort unzip uniq zipinfo; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		fail "required command '$command_name' is unavailable"
	fi
done

if [ ! -f "$archive_path" ]; then
	fail "archive does not exist: $archive_path"
fi

manifest_file=$(mktemp "/tmp/who-published-manifest-XXXXXX")
cleanup() {
	rm -f "$manifest_file"
}
trap cleanup EXIT INT TERM

if ! unzip -tqq "$archive_path" >/dev/null; then
	fail "archive is corrupt or unreadable"
fi
unzip -Z1 "$archive_path" > "$manifest_file"

if [ ! -s "$manifest_file" ]; then
	fail "archive is empty"
fi

if LC_ALL=C sort "$manifest_file" | uniq -d | grep -q .; then
	fail "archive contains duplicate entries"
fi

if zipinfo -l "$archive_path" | grep -Eq '^l[-rwx]'; then
	fail "archive contains a symbolic link"
fi

while IFS= read -r entry; do
	case "$entry" in
		"$plugin_slug"/|"$plugin_slug"/*)
			;;
		*)
			fail "entry is outside the $plugin_slug/ package root: $entry"
			;;
	esac

	relative_path=${entry#"$plugin_slug"/}
	case "/$relative_path" in
		*/../*|*/..|*/./*|*/.|*//*|*\\*)
			fail "entry has a non-normalized path: $entry"
			;;
	esac

	if [[ "$entry" =~ [[:cntrl:]] ]]; then
		fail "entry contains control characters"
	fi
done < "$manifest_file"

require_file() {
	local required_path="$plugin_slug/$1"
	if ! grep -Fqx "$required_path" "$manifest_file"; then
		fail "required file is missing: $required_path"
	fi
}

require_tree() {
	local required_prefix="$plugin_slug/$1/"
	if ! grep -F "$required_prefix" "$manifest_file" | grep -Ev '/$' | grep -q .; then
		fail "required tree is empty or missing: $required_prefix"
	fi
}

require_file "darven-who-published.php"
require_file "readme.txt"
require_tree "src"
require_file "assets/css/admin.css"
require_tree "languages"
require_file "vendor/autoload.php"

for forbidden_path in \
	.git \
	.github \
	build \
	dist \
	docs \
	tests \
	wordpress-org-assets \
	node_modules \
	bin \
	.gitignore \
	.distignore \
	.phpunit.result.cache \
	.wp-env.json \
	phpcs.xml.dist \
	phpunit.xml.dist \
	composer.json \
	composer.lock \
	README.md \
	vendor/bin \
	vendor/dealerdirect \
	vendor/phpunit \
	vendor/squizlabs \
	vendor/wp-coding-standards \
	vendor/yoast; do
	while IFS= read -r entry; do
		relative_path=${entry#"$plugin_slug"/}
		if [[ "$relative_path" == "$forbidden_path" || "$relative_path" == "$forbidden_path/"* ]]; then
			fail "forbidden development path is present: $plugin_slug/$forbidden_path"
		fi
	done < "$manifest_file"
done

if grep -Eq '(^|/)(\.DS_Store|Thumbs\.db|[^/]+~|[^/]+\.swp)(/|$)' "$manifest_file"; then
	fail "archive contains a temporary or operating-system file"
fi

echo "Build verification passed: $archive_path"
