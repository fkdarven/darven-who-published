#!/usr/bin/env bash

set -euo pipefail

plugin_slug="darven-who-published"
archive_path=${1:-}
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)

fail() {
	echo "Build verification failed: $1" >&2
	exit 1
}

if [ -z "$archive_path" ]; then
	fail "usage: $0 <plugin-zip>"
fi

for command_name in awk grep php sha256sum sort unzip uniq zipinfo; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		fail "required command '$command_name' is unavailable"
	fi
done

if [ ! -f "$archive_path" ]; then
	fail "archive does not exist: $archive_path"
fi

manifest_file=$(mktemp "/tmp/who-published-manifest-XXXXXX")
installed_json_file=$(mktemp "/tmp/who-published-installed-json-XXXXXX")
installed_php_file=$(mktemp "/tmp/who-published-installed-php-XXXXXX")
cleanup() {
	rm -f "$manifest_file" "$installed_json_file" "$installed_php_file"
}
trap cleanup EXIT INT TERM

if ! unzip -tqq "$archive_path" >/dev/null; then
	fail "archive is corrupt or unreadable"
fi
if ! php "$script_dir/validate-zip-manifest.php" "$archive_path" > "$manifest_file"; then
	fail "raw archive manifest validation failed"
fi

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

	case "$relative_path" in
		""|darven-who-published.php|readme.txt|assets|assets/|assets/*|languages|languages/|languages/*|src|src/|src/*|vendor|vendor/|vendor/autoload.php|vendor/composer|vendor/composer/|vendor/composer/ClassLoader.php|vendor/composer/InstalledVersions.php|vendor/composer/LICENSE|vendor/composer/autoload_classmap.php|vendor/composer/autoload_namespaces.php|vendor/composer/autoload_psr4.php|vendor/composer/autoload_real.php|vendor/composer/autoload_static.php|vendor/composer/installed.json|vendor/composer/installed.php)
			;;
		*)
			fail "path is outside the approved production manifest: $entry"
			;;
	esac
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
require_file "vendor/composer/ClassLoader.php"
require_file "vendor/composer/InstalledVersions.php"
require_file "vendor/composer/LICENSE"
require_file "vendor/composer/autoload_classmap.php"
require_file "vendor/composer/autoload_namespaces.php"
require_file "vendor/composer/autoload_psr4.php"
require_file "vendor/composer/autoload_real.php"
require_file "vendor/composer/autoload_static.php"
require_file "vendor/composer/installed.json"
require_file "vendor/composer/installed.php"

if ! unzip -p "$archive_path" "$plugin_slug/vendor/composer/installed.json" > "$installed_json_file"; then
	fail "could not read Composer installed.json"
fi
# The single-quoted expression is PHP source.
# shellcheck disable=SC2016
if ! php -r '
	try {
		$data = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
	} catch (Throwable $error) {
		exit(1);
	}
	if (
		! is_array($data)
		|| array() !== ($data["packages"] ?? null)
		|| false !== ($data["dev"] ?? null)
		|| array() !== ($data["dev-package-names"] ?? null)
	) {
		exit(1);
	}
' "$installed_json_file"; then
	fail "Composer installed.json contains production packages or invalid metadata"
fi

if ! unzip -p "$archive_path" "$plugin_slug/vendor/composer/installed.php" > "$installed_php_file"; then
	fail "could not read Composer installed.php"
fi
expected_installed_php_hash="daa1c7b63a9a93aa9a4841cdfef42c7db27914ccb7dcc00d81343a9cb89a949a"
actual_installed_php_hash=$(sha256sum "$installed_php_file" | awk '{ print $1 }')
if [ "$actual_installed_php_hash" != "$expected_installed_php_hash" ]; then
	fail "installed.php does not match the Composer 2.10.2 root-only contract"
fi

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
