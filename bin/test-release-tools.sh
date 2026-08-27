#!/usr/bin/env bash

set -u
set -o pipefail

plugin_slug="darven-who-published"
plugin_version="1.1.0"
script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
project_root=$(cd "$script_dir/.." && pwd)
test_root=$(mktemp -d "/tmp/who-published-release-tests-XXXXXX")
base_fixture="$test_root/base"
failures=0

cleanup() {
	rm -rf "$test_root"
}
trap cleanup EXIT INT TERM

fail() {
	echo "FAIL: $1" >&2
	return 1
}

for command_name in chmod composer cp dd git ln php rsync unzip zip; do
	if ! command -v "$command_name" >/dev/null 2>&1; then
		echo "Release-tool tests require '$command_name'." >&2
		exit 1
	fi
done

create_base_fixture() {
	if ! mkdir -p "$base_fixture"; then
		fail "could not create base fixture directory"
		return 1
	fi
	if ! rsync -a \
		--exclude='/.git' \
		--exclude='/build' \
		--exclude='/vendor' \
		"$project_root/" \
		"$base_fixture/"; then
		fail "could not copy the base fixture"
		return 1
	fi
	if ! git -C "$base_fixture" init -q -b fixture; then
		fail "could not initialize the base fixture repository"
		return 1
	fi
	if ! git -C "$base_fixture" add -A; then
		fail "could not stage the base fixture"
		return 1
	fi
	if ! git -C "$base_fixture" \
		-c user.name='Release Test' \
		-c user.email='release-test@example.invalid' \
		commit -qm 'test fixture'; then
		fail "could not commit the base fixture"
		return 1
	fi
}

new_fixture() {
	local fixture_name=$1
	local fixture_path="$test_root/$fixture_name"
	if ! git clone -q "$base_fixture" "$fixture_path"; then
		fail "could not clone fixture: $fixture_name"
		return 1
	fi
	printf '%s\n' "$fixture_path"
}

archive_path() {
	printf '%s/build/%s.%s.zip\n' "$1" "$plugin_slug" "$plugin_version"
}

assert_no_canonical_outputs() {
	local fixture_path=$1
	local canonical_archive
	canonical_archive=$(archive_path "$fixture_path")

	if [ -e "$canonical_archive" ]; then
		fail "failed build left canonical archive: $canonical_archive"
		return 1
	fi
	if [ -e "$fixture_path/build/plugin" ]; then
		fail "failed build left canonical plugin tree: $fixture_path/build/plugin"
		return 1
	fi
}

assert_raw_archive_entry_nonempty() {
	local archive=$1
	local raw_name=$2
	local encoded_name

	if [ ! -s "$archive" ]; then
		fail "malicious archive is missing or empty: $archive"
		return 1
	fi
	if ! unzip -tqq "$archive"; then
		fail "malicious archive failed ZIP integrity: $archive"
		return 1
	fi
	# The single-quoted expression is PHP source.
	# shellcheck disable=SC2016
	if ! encoded_name=$(php -r 'echo base64_encode($argv[1]);' "$raw_name"); then
		fail "could not encode expected raw archive name"
		return 1
	fi
	# The single-quoted expression is PHP source.
	# shellcheck disable=SC2016
	if ! php -r '
		$archive = file_get_contents($argv[1]);
		$expected = base64_decode($argv[2], true);
		if (false === $archive || false === $expected) { exit(2); }
		$eocd = strrpos($archive, "\x50\x4b\x05\x06");
		if (false === $eocd || $eocd + 22 > strlen($archive)) { exit(2); }
		$count = unpack("v", substr($archive, $eocd + 10, 2))[1];
		$cursor = unpack("V", substr($archive, $eocd + 16, 4))[1];
		for ($index = 0; $index < $count; ++$index) {
			if ("\x50\x4b\x01\x02" !== substr($archive, $cursor, 4)) { exit(2); }
			$size = unpack("V", substr($archive, $cursor + 24, 4))[1];
			$name_length = unpack("v", substr($archive, $cursor + 28, 2))[1];
			$extra_length = unpack("v", substr($archive, $cursor + 30, 2))[1];
			$comment_length = unpack("v", substr($archive, $cursor + 32, 2))[1];
			$name = substr($archive, $cursor + 46, $name_length);
			if ($name === $expected && $size > 0) { exit(0); }
			$cursor += 46 + $name_length + $extra_length + $comment_length;
		}
		exit(1);
	' "$archive" "$encoded_name"; then
		fail "archive does not contain the intended non-empty raw entry: $raw_name"
		return 1
	fi
}

extract_archive_fixture() {
	local archive=$1
	local unpacked=$2

	if [ ! -s "$archive" ]; then
		fail "baseline archive is missing or empty: $archive"
		return 1
	fi
	if ! unzip -tqq "$archive"; then
		fail "baseline archive failed ZIP integrity: $archive"
		return 1
	fi
	if ! mkdir -p "$unpacked"; then
		fail "could not create fixture extraction directory: $unpacked"
		return 1
	fi
	if ! unzip -q "$archive" -d "$unpacked"; then
		fail "could not extract baseline archive: $archive"
		return 1
	fi
	if [ ! -d "$unpacked/$plugin_slug" ]; then
		fail "baseline archive did not extract the plugin root"
		return 1
	fi
}

create_mutated_archive() {
	local unpacked=$1
	local malicious_archive=$2

	if ! (
		cd "$unpacked" || exit 1
		zip -X -qr "$malicious_archive" "$plugin_slug"
	); then
		fail "could not construct malicious archive: $malicious_archive"
		return 1
	fi
	if [ ! -s "$malicious_archive" ]; then
		fail "malicious archive is missing or empty: $malicious_archive"
		return 1
	fi
	if ! unzip -tqq "$malicious_archive"; then
		fail "malicious archive failed ZIP integrity: $malicious_archive"
		return 1
	fi
}

mutate_raw_archive() {
	local source_archive=$1
	local malicious_archive=$2
	local mutation=$3
	local target=${4:-}

	# The single-quoted expression is PHP source.
	# shellcheck disable=SC2016
	if ! php -r '
		$data = file_get_contents($argv[1]);
		$mutation = $argv[3];
		$target = $argv[4];
		if (false === $data || "" === $data) { exit(2); }
		$u16 = static fn(string $bytes, int $offset): int => unpack("v", substr($bytes, $offset, 2))[1];
		$u32 = static fn(string $bytes, int $offset): int => unpack("V", substr($bytes, $offset, 4))[1];
		$eocd = strrpos($data, "\x50\x4b\x05\x06");
		if (false === $eocd || $eocd + 22 !== strlen($data)) { exit(3); }
		$count = $u16($data, $eocd + 10);
		$central_size = $u32($data, $eocd + 12);
		$central_start = $u32($data, $eocd + 16);
		if ($central_start + $central_size !== $eocd) { exit(4); }

		switch ($mutation) {
			case "duplicate-central":
				$central = substr($data, $central_start, $central_size);
				$new_eocd = substr($data, $eocd, 22);
				$new_eocd = substr_replace($new_eocd, pack("V", strlen($data)), 16, 4);
				$data .= $central . $new_eocd;
				break;
			case "hidden-gap":
				$payload = "HIDDEN-PAYLOAD-CANARY";
				$data = substr($data, 0, $central_start) . $payload . substr($data, $central_start);
				$data = substr_replace($data, pack("V", $central_start + strlen($payload)), $eocd + strlen($payload) + 16, 4);
				break;
			case "eocd-comment":
				$comment = "EOCD-COMMENT-CANARY";
				$data = substr_replace($data, pack("v", strlen($comment)), $eocd + 20, 2) . $comment;
				break;
			case "prefix":
				$prefix = "PREFIX-CANARY";
				$prefix_length = strlen($prefix);
				$data = $prefix . $data;
				$cursor = $central_start + $prefix_length;
				for ($index = 0; $index < $count; ++$index) {
					if ("\x50\x4b\x01\x02" !== substr($data, $cursor, 4)) { exit(5); }
					$name_length = $u16($data, $cursor + 28);
					$extra_length = $u16($data, $cursor + 30);
					$comment_length = $u16($data, $cursor + 32);
					$local_offset = $u32($data, $cursor + 42);
					$data = substr_replace($data, pack("V", $local_offset + $prefix_length), $cursor + 42, 4);
					$cursor += 46 + $name_length + $extra_length + $comment_length;
				}
				$data = substr_replace($data, pack("V", $central_start + $prefix_length), $eocd + $prefix_length + 16, 4);
				break;
			case "chmod-0777":
			case "dos-origin":
			case "overlap":
				$cursor = $central_start;
				$changed = false;
				for ($index = 0; $index < $count; ++$index) {
					if ("\x50\x4b\x01\x02" !== substr($data, $cursor, 4)) { exit(6); }
					$name_length = $u16($data, $cursor + 28);
					$extra_length = $u16($data, $cursor + 30);
					$comment_length = $u16($data, $cursor + 32);
					$name = substr($data, $cursor + 46, $name_length);
					$compressed_size = $u32($data, $cursor + 20);
					if ("chmod-0777" === $mutation && $name === $target) {
						$attributes = $u32($data, $cursor + 38);
						$attributes = (0100777 << 16) | ($attributes & 0xffff);
						$data = substr_replace($data, pack("V", $attributes), $cursor + 38, 4);
						$changed = true;
					}
					if ("dos-origin" === $mutation && $name === $target) {
						$made_by = $u16($data, $cursor + 4) & 0xff;
						$data = substr_replace($data, pack("v", $made_by), $cursor + 4, 2);
						$changed = true;
					}
					if ("overlap" === $mutation && ! $changed && $compressed_size > 0) {
						$data = substr_replace($data, pack("V", $compressed_size + 1), $cursor + 20, 4);
						$changed = true;
					}
					$cursor += 46 + $name_length + $extra_length + $comment_length;
				}
				if (! $changed) { exit(7); }
				break;
			default:
				exit(8);
		}

		exit(false === file_put_contents($argv[2], $data) ? 9 : 0);
	' "$source_archive" "$malicious_archive" "$mutation" "$target"; then
		fail "could not create raw ZIP mutation: $mutation"
		return 1
	fi
	if [ ! -s "$malicious_archive" ]; then
		fail "raw ZIP mutation is missing or empty: $mutation"
		return 1
	fi
}

assert_raw_archive_structure() {
	local archive=$1
	local expected_structure=$2
	local target=${3:-}

	# The single-quoted expression is PHP source.
	# shellcheck disable=SC2016
	if ! php -r '
		$data = file_get_contents($argv[1]);
		$expected = $argv[2];
		$target = $argv[3];
		if (false === $data || "" === $data) { exit(2); }
		$u16 = static fn(string $bytes, int $offset): int => unpack("v", substr($bytes, $offset, 2))[1];
		$u32 = static fn(string $bytes, int $offset): int => unpack("V", substr($bytes, $offset, 4))[1];
		$eocd = strrpos($data, "\x50\x4b\x05\x06");
		if (false === $eocd || $eocd + 22 > strlen($data)) { exit(3); }
		$count = $u16($data, $eocd + 10);
		$central_start = $u32($data, $eocd + 16);
		$cursor = $central_start;
		$entries = [];
		for ($index = 0; $index < $count; ++$index) {
			if ("\x50\x4b\x01\x02" !== substr($data, $cursor, 4)) { exit(4); }
			$name_length = $u16($data, $cursor + 28);
			$extra_length = $u16($data, $cursor + 30);
			$comment_length = $u16($data, $cursor + 32);
			$entries[] = [
				"name" => substr($data, $cursor + 46, $name_length),
				"flags" => $u16($data, $cursor + 8),
				"compressed" => $u32($data, $cursor + 20),
				"attributes" => $u32($data, $cursor + 38),
				"made_by" => $u16($data, $cursor + 4),
				"local" => $u32($data, $cursor + 42),
			];
			$cursor += 46 + $name_length + $extra_length + $comment_length;
		}

		$valid_eocd = 0;
		$scan = 0;
		while (false !== ($position = strpos($data, "\x50\x4b\x05\x06", $scan))) {
			if ($position + 22 <= strlen($data)) {
				$size = $u32($data, $position + 12);
				$start = $u32($data, $position + 16);
				if ($start + $size === $position) { ++$valid_eocd; }
			}
			$scan = $position + 1;
		}

		switch ($expected) {
			case "duplicate-central":
				exit($valid_eocd >= 2 ? 0 : 10);
			case "hidden-gap":
				$marker = "HIDDEN-PAYLOAD-CANARY";
				exit(substr($data, $central_start - strlen($marker), strlen($marker)) === $marker ? 0 : 11);
			case "eocd-comment":
				$comment_length = $u16($data, $eocd + 20);
				exit($comment_length > 0 && $eocd + 22 + $comment_length === strlen($data) ? 0 : 12);
			case "prefix":
				$minimum = min(array_column($entries, "local"));
				exit(str_starts_with($data, "PREFIX-CANARY") && $minimum > 0 ? 0 : 13);
			case "bit3":
				foreach ($entries as $entry) {
					if (($entry["flags"] & 8) !== 0 && ($u16($data, $entry["local"] + 6) & 8) !== 0 && substr_count($data, "\x50\x4b\x07\x08") > 0) { exit(0); }
				}
				exit(14);
			case "case-fold":
				$keys = [];
				foreach ($entries as $entry) {
					$key = strtolower(rtrim($entry["name"], "/"));
					if (isset($keys[$key])) { exit(0); }
					$keys[$key] = true;
				}
				exit(15);
			case "chmod-0777":
				foreach ($entries as $entry) {
					$mode = ($entry["attributes"] >> 16) & 0xffff;
					$host = ($entry["made_by"] >> 8) & 0xff;
					if ($entry["name"] === $target && 3 === $host && 0100777 === $mode) { exit(0); }
				}
				exit(16);
			case "dos-origin":
				foreach ($entries as $entry) {
					$host = ($entry["made_by"] >> 8) & 0xff;
					if ($entry["name"] === $target && 0 === $host) { exit(0); }
				}
				exit(19);
			case "overlap":
				usort($entries, static fn(array $left, array $right): int => $left["local"] <=> $right["local"]);
				for ($index = 0; $index + 1 < count($entries); ++$index) {
					$entry = $entries[$index];
					$local_name_length = $u16($data, $entry["local"] + 26);
					$local_extra_length = $u16($data, $entry["local"] + 28);
					$end = $entry["local"] + 30 + $local_name_length + $local_extra_length + $entry["compressed"];
					if ($end > $entries[$index + 1]["local"]) { exit(0); }
				}
				exit(17);
			default:
				exit(18);
		}
	' "$archive" "$expected_structure" "$target"; then
		fail "archive does not contain the intended raw structure: $expected_structure"
		return 1
	fi
}

assert_archive_integrity() {
	local archive=$1
	if [ ! -s "$archive" ] || ! unzip -tqq "$archive"; then
		fail "malicious archive is missing, empty, or corrupt: $archive"
		return 1
	fi
}

run_manifest_validator() {
	local fixture_path=$1
	local archive=$2
	local validator="$fixture_path/bin/validate-zip-manifest.php"
	if [ ! -f "$validator" ] || ! php -l "$validator" >/dev/null; then
		fail "raw manifest validator is missing or invalid: $validator"
		return 99
	fi
	php "$validator" "$archive"
}

run_archive_verifier() {
	local fixture_path=$1
	local archive=$2
	local verifier=${RELEASE_VERIFIER_OVERRIDE:-$fixture_path/bin/verify-build.sh}

	if [ ! -f "$verifier" ] || ! bash -n "$verifier"; then
		fail "archive verifier is missing or invalid: $verifier"
		return 99
	fi
	bash "$verifier" "$archive"
}

expect_archive_verifier_rejection() {
	local fixture_path=$1
	local archive=$2
	local accepted_message=$3

	if run_archive_verifier "$fixture_path" "$archive" >/dev/null 2>&1; then
		fail "$accepted_message"
		return 1
	elif [ 99 -eq "$?" ]; then
		return 1
	fi
}

build_fixture() {
	local fixture_path=$1
	local log_path=$2
	shift 2
	if [ ! -d "$fixture_path/.git" ] || [ ! -f "$fixture_path/bin/build-plugin.sh" ]; then
		fail "fixture is missing or incomplete: $fixture_path"
		return 1
	fi
	(
		cd "$fixture_path" || exit 1
		env "$@" bash bin/build-plugin.sh
	) > "$log_path" 2>&1
}

expect_build_failure_without_outputs() {
	local fixture_path=$1
	local log_path=$2
	shift 2

	if build_fixture "$fixture_path" "$log_path" "$@"; then
		fail "build unexpectedly succeeded; log: $log_path"
		return 1
	fi
	assert_no_canonical_outputs "$fixture_path"
}

test_untracked_files_are_excluded() {
	local fixture_path
	local archive
	local inventory="$test_root/untracked-env-inventory.txt"
	if ! fixture_path=$(new_fixture untracked-env); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")
	if ! printf 'SECRET=must-not-ship\n' > "$fixture_path/.env"; then
		fail "could not create untracked-file canary"
		return 1
	fi

	if ! build_fixture "$fixture_path" "$test_root/untracked-env.log"; then
		fail "build with benign untracked file failed"
		return 1
	fi
	unzip -Z1 "$archive" > "$inventory"
	if grep -Fqx "$plugin_slug/.env" "$inventory"; then
		fail "untracked .env entered the production archive"
		return 1
	fi
	if ! run_archive_verifier "$fixture_path" "$archive" >/dev/null; then
		fail "clean tracked-file archive failed verification"
		return 1
	fi
}

test_untracked_source_symlink_is_rejected() {
	local fixture_path
	if ! fixture_path=$(new_fixture untracked-symlink); then
		return 1
	fi
	if ! printf 'external secret\n' > "$test_root/external-secret.txt"; then
		fail "could not create external symlink target"
		return 1
	fi
	if ! ln -s "$test_root/external-secret.txt" "$fixture_path/external-link.txt"; then
		fail "could not create untracked source symlink"
		return 1
	fi
	expect_build_failure_without_outputs "$fixture_path" "$test_root/untracked-symlink.log"
}

test_tracked_symlink_mode_is_rejected() {
	local fixture_path
	if ! fixture_path=$(new_fixture tracked-symlink); then
		return 1
	fi
	if ! printf 'tracked external secret\n' > "$test_root/tracked-external-secret.txt"; then
		fail "could not create tracked symlink target"
		return 1
	fi
	if ! ln -s "$test_root/tracked-external-secret.txt" "$fixture_path/src/tracked-link.php"; then
		fail "could not create tracked symlink"
		return 1
	fi
	if ! git -C "$fixture_path" add src/tracked-link.php; then
		fail "could not stage tracked symlink fixture"
		return 1
	fi
	if ! git -C "$fixture_path" \
		-c user.name='Release Test' \
		-c user.email='release-test@example.invalid' \
		commit -qm 'add tracked symlink fixture'; then
		fail "could not commit tracked symlink fixture"
		return 1
	fi
	# Keep the index symlink mode while making the worktree path regular. This
	# proves the Git-mode gate independently from the filesystem symlink scan.
	if ! rm "$fixture_path/src/tracked-link.php"; then
		fail "could not replace tracked symlink fixture"
		return 1
	fi
	if ! printf '%s\n' '<?php // Worktree replacement for Git-mode test.' > "$fixture_path/src/tracked-link.php"; then
		fail "could not create tracked-symlink worktree replacement"
		return 1
	fi
	expect_build_failure_without_outputs "$fixture_path" "$test_root/tracked-symlink.log"
}

test_control_character_path_is_rejected() {
	local fixture_path
	local control_path
	if ! fixture_path=$(new_fixture control-path); then
		return 1
	fi
	control_path="$fixture_path/src/collision"$'\t'"name.php"
	if ! printf '%s\n' '<?php // Control-path canary.' > "$control_path"; then
		fail "could not create control-path canary"
		return 1
	fi
	if ! git -C "$fixture_path" add -A; then
		fail "could not stage control-path fixture"
		return 1
	fi
	if ! git -C "$fixture_path" \
		-c user.name='Release Test' \
		-c user.email='release-test@example.invalid' \
		commit -qm 'add control path fixture'; then
		fail "could not commit control-path fixture"
		return 1
	fi
	expect_build_failure_without_outputs "$fixture_path" "$test_root/control-path.log"
}

test_unapproved_vendor_package_is_rejected() {
	local fixture_path
	local archive
	local unpacked
	local malicious_archive="$test_root/vendor-canary.zip"
	local raw_name="$plugin_slug/vendor/doctrine/instantiator/canary.php"
	if ! fixture_path=$(new_fixture vendor-canary); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")
	unpacked="$test_root/vendor-canary-unpacked"

	if ! build_fixture "$fixture_path" "$test_root/vendor-canary-build.log"; then
		fail "baseline vendor-canary build failed"
		return 1
	fi
	if ! extract_archive_fixture "$archive" "$unpacked"; then
		return 1
	fi
	if ! mkdir -p "$unpacked/$plugin_slug/vendor/doctrine/instantiator"; then
		fail "could not create unapproved vendor fixture path"
		return 1
	fi
	if ! printf '%s\n' '<?php // Unapproved vendor canary.' > "$unpacked/$raw_name"; then
		fail "could not create unapproved vendor canary"
		return 1
	fi
	if ! create_mutated_archive "$unpacked" "$malicious_archive"; then
		return 1
	fi
	if ! assert_raw_archive_entry_nonempty "$malicious_archive" "$raw_name"; then
		return 1
	fi
	if run_archive_verifier "$fixture_path" "$malicious_archive" >/dev/null 2>&1; then
		fail "verifier accepted an unapproved vendor package"
		return 1
	elif [ 99 -eq "$?" ]; then
		return 1
	fi
}

test_raw_control_archive_is_rejected() {
	local fixture_path
	local archive
	local unpacked="$test_root/raw-control-unpacked"
	local malicious_archive="$test_root/raw-control.zip"
	local raw_name="$plugin_slug/src/control"$'\t'"name.php"
	if ! fixture_path=$(new_fixture raw-control); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")

	if ! build_fixture "$fixture_path" "$test_root/raw-control-build.log"; then
		fail "baseline raw-control build failed"
		return 1
	fi
	if ! extract_archive_fixture "$archive" "$unpacked"; then
		return 1
	fi
	if ! printf '%s\n' '<?php // Raw control canary.' > "$unpacked/$raw_name"; then
		fail "could not create raw-control canary"
		return 1
	fi
	if ! create_mutated_archive "$unpacked" "$malicious_archive"; then
		return 1
	fi
	if ! assert_raw_archive_entry_nonempty "$malicious_archive" "$raw_name"; then
		return 1
	fi
	if run_archive_verifier "$fixture_path" "$malicious_archive" >/dev/null 2>&1; then
		fail "verifier accepted a raw control character in an archive name"
		return 1
	elif [ 99 -eq "$?" ]; then
		return 1
	fi
}

test_composer_subtree_canary_is_rejected() {
	local fixture_path
	local archive
	local unpacked="$test_root/composer-canary-unpacked"
	local malicious_archive="$test_root/composer-canary.zip"
	local raw_name="$plugin_slug/vendor/composer/canary.php"
	if ! fixture_path=$(new_fixture composer-canary); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")

	if ! build_fixture "$fixture_path" "$test_root/composer-canary-build.log"; then
		fail "baseline Composer-canary build failed"
		return 1
	fi
	if ! extract_archive_fixture "$archive" "$unpacked"; then
		return 1
	fi
	if ! mkdir -p "$unpacked/$plugin_slug/vendor/composer"; then
		fail "could not create Composer-canary fixture path"
		return 1
	fi
	if ! printf '%s\n' '<?php // Composer subtree canary.' > "$unpacked/$raw_name"; then
		fail "could not create Composer subtree canary"
		return 1
	fi
	if ! create_mutated_archive "$unpacked" "$malicious_archive"; then
		return 1
	fi
	if ! assert_raw_archive_entry_nonempty "$malicious_archive" "$raw_name"; then
		return 1
	fi
	if run_archive_verifier "$fixture_path" "$malicious_archive" >/dev/null 2>&1; then
		fail "verifier accepted an unapproved vendor/composer file"
		return 1
	elif [ 99 -eq "$?" ]; then
		return 1
	fi
}

test_production_composer_package_is_rejected() {
	local fixture_path
	local archive
	local unpacked="$test_root/composer-package-unpacked"
	local malicious_archive="$test_root/composer-package.zip"
	local raw_name="$plugin_slug/vendor/composer/installed.json"
	local installed_json
	if ! fixture_path=$(new_fixture composer-package); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")

	if ! build_fixture "$fixture_path" "$test_root/composer-package-build.log"; then
		fail "baseline Composer-package build failed"
		return 1
	fi
	if ! extract_archive_fixture "$archive" "$unpacked"; then
		return 1
	fi
	if ! printf '%s\n' '{"packages":[{"name":"canary/package"}],"dev":false,"dev-package-names":[]}' > "$unpacked/$raw_name"; then
		fail "could not create production-package Composer metadata"
		return 1
	fi
	if ! create_mutated_archive "$unpacked" "$malicious_archive"; then
		return 1
	fi
	if ! assert_raw_archive_entry_nonempty "$malicious_archive" "$raw_name"; then
		return 1
	fi
	if ! installed_json=$(unzip -p "$malicious_archive" "$raw_name"); then
		fail "could not read production-package Composer metadata"
		return 1
	fi
	case "$installed_json" in
		*'"name":"canary/package"'*)
			;;
		*)
			fail "malicious archive does not contain the intended Composer package"
			return 1
			;;
	esac
	if run_archive_verifier "$fixture_path" "$malicious_archive" >/dev/null 2>&1; then
		fail "verifier accepted a production Composer package"
		return 1
	elif [ 99 -eq "$?" ]; then
		return 1
	fi
}

test_duplicate_central_directory_is_rejected() {
	local fixture_path
	local archive
	local malicious_archive="$test_root/duplicate-central.zip"
	if ! fixture_path=$(new_fixture duplicate-central); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")
	if ! build_fixture "$fixture_path" "$test_root/duplicate-central-build.log"; then
		fail "baseline duplicate-central build failed"
		return 1
	fi
	if ! mutate_raw_archive "$archive" "$malicious_archive" duplicate-central; then
		return 1
	fi
	if ! assert_raw_archive_structure "$malicious_archive" duplicate-central; then
		return 1
	fi
	if ! assert_archive_integrity "$malicious_archive"; then
		return 1
	fi
	expect_archive_verifier_rejection "$fixture_path" "$malicious_archive" "verifier accepted two valid central directories and EOCD records"
}

test_hidden_precentral_payload_is_rejected() {
	local fixture_path
	local archive
	local malicious_archive="$test_root/hidden-gap.zip"
	if ! fixture_path=$(new_fixture hidden-gap); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")
	if ! build_fixture "$fixture_path" "$test_root/hidden-gap-build.log"; then
		fail "baseline hidden-gap build failed"
		return 1
	fi
	if ! mutate_raw_archive "$archive" "$malicious_archive" hidden-gap; then
		return 1
	fi
	if ! assert_raw_archive_structure "$malicious_archive" hidden-gap; then
		return 1
	fi
	if ! assert_archive_integrity "$malicious_archive"; then
		return 1
	fi
	expect_archive_verifier_rejection "$fixture_path" "$malicious_archive" "verifier accepted hidden bytes before the central directory"
}

test_eocd_comment_is_rejected() {
	local fixture_path
	local archive
	local malicious_archive="$test_root/eocd-comment.zip"
	if ! fixture_path=$(new_fixture eocd-comment); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")
	if ! build_fixture "$fixture_path" "$test_root/eocd-comment-build.log"; then
		fail "baseline EOCD-comment build failed"
		return 1
	fi
	if ! mutate_raw_archive "$archive" "$malicious_archive" eocd-comment; then
		return 1
	fi
	if ! assert_raw_archive_structure "$malicious_archive" eocd-comment; then
		return 1
	fi
	if ! assert_archive_integrity "$malicious_archive"; then
		return 1
	fi
	expect_archive_verifier_rejection "$fixture_path" "$malicious_archive" "verifier accepted a nonempty EOCD comment"
}

test_archive_prefix_is_rejected() {
	local fixture_path
	local archive
	local malicious_archive="$test_root/archive-prefix.zip"
	if ! fixture_path=$(new_fixture archive-prefix); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")
	if ! build_fixture "$fixture_path" "$test_root/archive-prefix-build.log"; then
		fail "baseline archive-prefix build failed"
		return 1
	fi
	if ! mutate_raw_archive "$archive" "$malicious_archive" prefix; then
		return 1
	fi
	if ! assert_raw_archive_structure "$malicious_archive" prefix; then
		return 1
	fi
	if ! assert_archive_integrity "$malicious_archive"; then
		return 1
	fi
	expect_archive_verifier_rejection "$fixture_path" "$malicious_archive" "verifier accepted bytes before the first local record"
}

test_data_descriptors_are_rejected() {
	local fixture_path
	local archive
	local unpacked="$test_root/data-descriptor-unpacked"
	local malicious_archive="$test_root/data-descriptor.zip"
	if ! fixture_path=$(new_fixture data-descriptor); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")
	if ! build_fixture "$fixture_path" "$test_root/data-descriptor-build.log"; then
		fail "baseline data-descriptor build failed"
		return 1
	fi
	if ! extract_archive_fixture "$archive" "$unpacked"; then
		return 1
	fi
	if ! (
		set -o pipefail
		cd "$unpacked" || exit 1
		zip -X -qr - "$plugin_slug" | dd of="$malicious_archive" status=none
	); then
		fail "could not create streamed data-descriptor archive"
		return 1
	fi
	if ! assert_raw_archive_structure "$malicious_archive" bit3; then
		return 1
	fi
	if ! assert_archive_integrity "$malicious_archive"; then
		return 1
	fi
	expect_archive_verifier_rejection "$fixture_path" "$malicious_archive" "verifier accepted bit-3 data descriptors"
}

test_case_fold_collision_is_rejected() {
	local fixture_path
	local archive
	local unpacked="$test_root/case-fold-unpacked"
	local malicious_archive="$test_root/case-fold.zip"
	if ! fixture_path=$(new_fixture case-fold); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")
	if ! build_fixture "$fixture_path" "$test_root/case-fold-build.log"; then
		fail "baseline case-fold build failed"
		return 1
	fi
	if ! extract_archive_fixture "$archive" "$unpacked"; then
		return 1
	fi
	if ! mkdir -p "$unpacked/$plugin_slug/src/core"; then
		fail "could not create case-fold collision directory"
		return 1
	fi
	if ! cp "$unpacked/$plugin_slug/src/Core/Starter.php" "$unpacked/$plugin_slug/src/core/Starter.php"; then
		fail "could not create case-fold collision entry"
		return 1
	fi
	if ! create_mutated_archive "$unpacked" "$malicious_archive"; then
		return 1
	fi
	if ! assert_raw_archive_structure "$malicious_archive" case-fold; then
		return 1
	fi
	expect_archive_verifier_rejection "$fixture_path" "$malicious_archive" "verifier accepted an ASCII case-fold path collision"
}

test_non_ascii_archive_name_is_rejected() {
	local fixture_path
	local archive
	local unpacked="$test_root/non-ascii-unpacked"
	local malicious_archive="$test_root/non-ascii.zip"
	local raw_name="$plugin_slug/src/caf"$'\xc3\xa9'".php"
	if ! fixture_path=$(new_fixture non-ascii); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")
	if ! build_fixture "$fixture_path" "$test_root/non-ascii-build.log"; then
		fail "baseline non-ASCII build failed"
		return 1
	fi
	if ! extract_archive_fixture "$archive" "$unpacked"; then
		return 1
	fi
	if ! printf '%s\n' '<?php // Non-ASCII path canary.' > "$unpacked/$raw_name"; then
		fail "could not create non-ASCII archive entry"
		return 1
	fi
	if ! create_mutated_archive "$unpacked" "$malicious_archive"; then
		return 1
	fi
	if ! assert_raw_archive_entry_nonempty "$malicious_archive" "$raw_name"; then
		return 1
	fi
	expect_archive_verifier_rejection "$fixture_path" "$malicious_archive" "verifier accepted a non-ASCII archive name"
}

test_world_writable_mode_is_rejected() {
	local fixture_path
	local archive
	local malicious_archive="$test_root/chmod-0777.zip"
	local target="$plugin_slug/darven-who-published.php"
	if ! fixture_path=$(new_fixture chmod-0777); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")
	if ! build_fixture "$fixture_path" "$test_root/chmod-0777-build.log"; then
		fail "baseline chmod-0777 build failed"
		return 1
	fi
	if ! mutate_raw_archive "$archive" "$malicious_archive" chmod-0777 "$target"; then
		return 1
	fi
	if ! assert_raw_archive_structure "$malicious_archive" chmod-0777 "$target"; then
		return 1
	fi
	if ! assert_archive_integrity "$malicious_archive"; then
		return 1
	fi
	expect_archive_verifier_rejection "$fixture_path" "$malicious_archive" "verifier accepted Unix mode 0777"
}

test_non_unix_origin_is_rejected() {
	local fixture_path
	local archive
	local malicious_archive="$test_root/dos-origin.zip"
	local target="$plugin_slug/darven-who-published.php"
	if ! fixture_path=$(new_fixture dos-origin); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")
	if ! build_fixture "$fixture_path" "$test_root/dos-origin-build.log"; then
		fail "baseline DOS-origin build failed"
		return 1
	fi
	if ! mutate_raw_archive "$archive" "$malicious_archive" dos-origin "$target"; then
		return 1
	fi
	if ! assert_raw_archive_structure "$malicious_archive" dos-origin "$target"; then
		return 1
	fi
	if ! assert_archive_integrity "$malicious_archive"; then
		return 1
	fi
	expect_archive_verifier_rejection "$fixture_path" "$malicious_archive" "verifier accepted a non-Unix archive origin"
}

test_overlapping_local_intervals_are_rejected() {
	local fixture_path
	local archive
	local malicious_archive="$test_root/local-overlap.zip"
	if ! fixture_path=$(new_fixture local-overlap); then
		return 1
	fi
	archive=$(archive_path "$fixture_path")
	if ! build_fixture "$fixture_path" "$test_root/local-overlap-build.log"; then
		fail "baseline local-overlap build failed"
		return 1
	fi
	if ! mutate_raw_archive "$archive" "$malicious_archive" overlap; then
		return 1
	fi
	if ! assert_raw_archive_structure "$malicious_archive" overlap; then
		return 1
	fi
	if run_manifest_validator "$fixture_path" "$malicious_archive" >/dev/null 2>&1; then
		fail "raw manifest validator accepted overlapping local intervals"
		return 1
	elif [ 99 -eq "$?" ]; then
		return 1
	fi
}

test_invalid_epoch_removes_stale_outputs() {
	local fixture_path
	if ! fixture_path=$(new_fixture invalid-epoch); then
		return 1
	fi
	if ! build_fixture "$fixture_path" "$test_root/invalid-epoch-baseline.log"; then
		fail "baseline invalid-epoch build failed"
		return 1
	fi
	expect_build_failure_without_outputs \
		"$fixture_path" \
		"$test_root/invalid-epoch-failure.log" \
		SOURCE_DATE_EPOCH=invalid
}

test_composer_failure_removes_stale_outputs() {
	local fixture_path
	local fake_bin="$test_root/failing-composer-bin"
	if ! fixture_path=$(new_fixture composer-failure); then
		return 1
	fi
	if ! build_fixture "$fixture_path" "$test_root/composer-failure-baseline.log"; then
		fail "baseline Composer-failure build failed"
		return 1
	fi
	if ! mkdir -p "$fake_bin"; then
		fail "could not create failing Composer fixture directory"
		return 1
	fi
	if ! printf '%s\n' '#!/usr/bin/env bash' 'exit 42' > "$fake_bin/composer"; then
		fail "could not create failing Composer fixture"
		return 1
	fi
	if ! chmod 0755 "$fake_bin/composer"; then
		fail "could not make failing Composer fixture executable"
		return 1
	fi
	expect_build_failure_without_outputs \
		"$fixture_path" \
		"$test_root/composer-failure.log" \
		PATH="$fake_bin:$PATH"
}

run_test() {
	local test_name=$1
	shift
	printf 'RUN  %s\n' "$test_name"
	if "$@"; then
		printf 'PASS %s\n' "$test_name"
	else
		failures=$(( failures + 1 ))
	fi
}

if ! create_base_fixture; then
	echo "Release-tool fixture setup failed." >&2
	exit 1
fi
run_test "untracked files are excluded" test_untracked_files_are_excluded
run_test "untracked source symlinks are rejected" test_untracked_source_symlink_is_rejected
run_test "tracked symlink modes are rejected" test_tracked_symlink_mode_is_rejected
run_test "control-character paths are rejected" test_control_character_path_is_rejected
run_test "unapproved vendor packages are rejected" test_unapproved_vendor_package_is_rejected
run_test "raw control archive names are rejected" test_raw_control_archive_is_rejected
run_test "vendor/composer canaries are rejected" test_composer_subtree_canary_is_rejected
run_test "production Composer packages are rejected" test_production_composer_package_is_rejected
run_test "duplicate central directories are rejected" test_duplicate_central_directory_is_rejected
run_test "hidden pre-central payloads are rejected" test_hidden_precentral_payload_is_rejected
run_test "EOCD comments are rejected" test_eocd_comment_is_rejected
run_test "archive prefixes are rejected" test_archive_prefix_is_rejected
run_test "bit-3 data descriptors are rejected" test_data_descriptors_are_rejected
run_test "ASCII case-fold collisions are rejected" test_case_fold_collision_is_rejected
run_test "non-ASCII archive names are rejected" test_non_ascii_archive_name_is_rejected
run_test "world-writable archive modes are rejected" test_world_writable_mode_is_rejected
run_test "non-Unix archive origins are rejected" test_non_unix_origin_is_rejected
run_test "overlapping local intervals are rejected" test_overlapping_local_intervals_are_rejected
run_test "invalid epochs remove stale outputs" test_invalid_epoch_removes_stale_outputs
run_test "Composer failures remove stale outputs" test_composer_failure_removes_stale_outputs

if [ "$failures" -ne 0 ]; then
	echo "$failures release-tool regression test(s) failed." >&2
	exit 1
fi

echo "All release-tool regression tests passed."
