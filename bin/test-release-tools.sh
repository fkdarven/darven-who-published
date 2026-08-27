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

for command_name in chmod composer git ln php rsync unzip zip; do
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
run_test "invalid epochs remove stale outputs" test_invalid_epoch_removes_stale_outputs
run_test "Composer failures remove stale outputs" test_composer_failure_removes_stale_outputs

if [ "$failures" -ne 0 ]; then
	echo "$failures release-tool regression test(s) failed." >&2
	exit 1
fi

echo "All release-tool regression tests passed."
