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

create_base_fixture() {
	mkdir -p "$base_fixture"
	rsync -a \
		--exclude='/.git' \
		--exclude='/build' \
		--exclude='/vendor' \
		"$project_root/" \
		"$base_fixture/"
	git -C "$base_fixture" init -q -b fixture
	git -C "$base_fixture" add -A
	git -C "$base_fixture" \
		-c user.name='Release Test' \
		-c user.email='release-test@example.invalid' \
		commit -qm 'test fixture'
}

new_fixture() {
	local fixture_name=$1
	local fixture_path="$test_root/$fixture_name"
	git clone -q "$base_fixture" "$fixture_path"
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

build_fixture() {
	local fixture_path=$1
	local log_path=$2
	shift 2
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
	fixture_path=$(new_fixture untracked-env)
	archive=$(archive_path "$fixture_path")
	printf 'SECRET=must-not-ship\n' > "$fixture_path/.env"

	if ! build_fixture "$fixture_path" "$test_root/untracked-env.log"; then
		fail "build with benign untracked file failed"
		return 1
	fi
	unzip -Z1 "$archive" > "$inventory"
	if grep -Fqx "$plugin_slug/.env" "$inventory"; then
		fail "untracked .env entered the production archive"
		return 1
	fi
	if ! bash "$fixture_path/bin/verify-build.sh" "$archive" >/dev/null; then
		fail "clean tracked-file archive failed verification"
		return 1
	fi
}

test_untracked_source_symlink_is_rejected() {
	local fixture_path
	fixture_path=$(new_fixture untracked-symlink)
	printf 'external secret\n' > "$test_root/external-secret.txt"
	ln -s "$test_root/external-secret.txt" "$fixture_path/external-link.txt"
	expect_build_failure_without_outputs "$fixture_path" "$test_root/untracked-symlink.log"
}

test_tracked_symlink_mode_is_rejected() {
	local fixture_path
	fixture_path=$(new_fixture tracked-symlink)
	printf 'tracked external secret\n' > "$test_root/tracked-external-secret.txt"
	ln -s "$test_root/tracked-external-secret.txt" "$fixture_path/src/tracked-link.php"
	git -C "$fixture_path" add src/tracked-link.php
	git -C "$fixture_path" \
		-c user.name='Release Test' \
		-c user.email='release-test@example.invalid' \
		commit -qm 'add tracked symlink fixture'
	# Keep the index symlink mode while making the worktree path regular. This
	# proves the Git-mode gate independently from the filesystem symlink scan.
	rm "$fixture_path/src/tracked-link.php"
	printf '%s\n' '<?php // Worktree replacement for Git-mode test.' > "$fixture_path/src/tracked-link.php"
	expect_build_failure_without_outputs "$fixture_path" "$test_root/tracked-symlink.log"
}

test_control_character_path_is_rejected() {
	local fixture_path
	local control_path
	fixture_path=$(new_fixture control-path)
	control_path="$fixture_path/src/collision"$'\t'"name.php"
	printf '%s\n' '<?php // Control-path canary.' > "$control_path"
	git -C "$fixture_path" add -A
	git -C "$fixture_path" \
		-c user.name='Release Test' \
		-c user.email='release-test@example.invalid' \
		commit -qm 'add control path fixture'
	expect_build_failure_without_outputs "$fixture_path" "$test_root/control-path.log"
}

test_unapproved_vendor_package_is_rejected() {
	local fixture_path
	local archive
	local unpacked
	fixture_path=$(new_fixture vendor-canary)
	archive=$(archive_path "$fixture_path")
	unpacked="$test_root/vendor-canary-unpacked"

	if ! build_fixture "$fixture_path" "$test_root/vendor-canary-build.log"; then
		fail "baseline vendor-canary build failed"
		return 1
	fi
	mkdir -p "$unpacked"
	unzip -q "$archive" -d "$unpacked"
	mkdir -p "$unpacked/$plugin_slug/vendor/doctrine/instantiator"
	printf '%s\n' '<?php // Unapproved vendor canary.' > "$unpacked/$plugin_slug/vendor/doctrine/instantiator/canary.php"
	(
		cd "$unpacked" || exit 1
		zip -X -qr "$test_root/vendor-canary.zip" "$plugin_slug"
	)
	if bash "$fixture_path/bin/verify-build.sh" "$test_root/vendor-canary.zip" >/dev/null 2>&1; then
		fail "verifier accepted an unapproved vendor package"
		return 1
	fi
}

test_invalid_epoch_removes_stale_outputs() {
	local fixture_path
	fixture_path=$(new_fixture invalid-epoch)
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
	fixture_path=$(new_fixture composer-failure)
	if ! build_fixture "$fixture_path" "$test_root/composer-failure-baseline.log"; then
		fail "baseline Composer-failure build failed"
		return 1
	fi
	mkdir -p "$fake_bin"
	printf '%s\n' '#!/usr/bin/env bash' 'exit 42' > "$fake_bin/composer"
	chmod 0755 "$fake_bin/composer"
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

create_base_fixture
run_test "untracked files are excluded" test_untracked_files_are_excluded
run_test "untracked source symlinks are rejected" test_untracked_source_symlink_is_rejected
run_test "tracked symlink modes are rejected" test_tracked_symlink_mode_is_rejected
run_test "control-character paths are rejected" test_control_character_path_is_rejected
run_test "unapproved vendor packages are rejected" test_unapproved_vendor_package_is_rejected
run_test "invalid epochs remove stale outputs" test_invalid_epoch_removes_stale_outputs
run_test "Composer failures remove stale outputs" test_composer_failure_removes_stale_outputs

if [ "$failures" -ne 0 ]; then
	echo "$failures release-tool regression test(s) failed." >&2
	exit 1
fi

echo "All release-tool regression tests passed."
