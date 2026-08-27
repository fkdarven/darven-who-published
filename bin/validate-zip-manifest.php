<?php
/**
 * Validate and emit raw ZIP entry names from the central directory.
 *
 * This intentionally does not use ZipArchive or Info-ZIP's rendered listing:
 * both can decode or display names before callers inspect them. The release
 * verifier needs the exact bytes stored in the archive.
 *
 * @package DarvenWhoPublished
 */

declare(strict_types=1);

// This standalone CLI parser runs before WordPress is loaded and must read raw bytes.
// phpcs:disable WordPress.WP.AlternativeFunctions

const PACKAGE_ROOT      = 'darven-who-published/';
const EOCD_SIGNATURE    = "\x50\x4b\x05\x06";
const CENTRAL_SIGNATURE = "\x50\x4b\x01\x02";
const LOCAL_SIGNATURE   = "\x50\x4b\x03\x04";

/**
 * Stop validation with a stable error message.
 *
 * @param string $message Failure detail.
 * @return never
 */
function fail_validation( string $message ): void {
	fwrite( STDERR, "Raw ZIP validation failed: $message\n" );
	exit( 1 );
}

/**
 * Read an exact byte range from the archive.
 *
 * @param resource $stream Archive stream.
 * @param int      $offset Zero-based offset.
 * @param int      $length Number of bytes.
 * @param int      $size   Archive size.
 * @return string
 */
function read_exact( $stream, int $offset, int $length, int $size ): string {
	if ( $offset < 0 || $length < 0 || $offset > $size || $length > $size - $offset ) {
		fail_validation( 'a ZIP structure points outside the archive.' );
	}
	if ( 0 !== fseek( $stream, $offset ) ) {
		fail_validation( "could not seek to archive offset $offset." );
	}

	$data        = '';
	$data_length = 0;
	while ( $data_length < $length ) {
		$chunk = fread( $stream, $length - $data_length );
		if ( false === $chunk || '' === $chunk ) {
			fail_validation( "could not read $length bytes at archive offset $offset." );
		}
		$data        .= $chunk;
		$data_length += strlen( $chunk );
	}

	return $data;
}

/**
 * Decode a little-endian unsigned 16-bit integer.
 *
 * @param string $bytes Input bytes.
 * @param int    $offset Byte offset.
 * @return int
 */
function uint16( string $bytes, int $offset ): int {
	$value = unpack( 'vvalue', substr( $bytes, $offset, 2 ) );
	if ( false === $value ) {
		fail_validation( 'could not decode a 16-bit ZIP field.' );
	}
	return $value['value'];
}

/**
 * Decode a little-endian unsigned 32-bit integer.
 *
 * @param string $bytes Input bytes.
 * @param int    $offset Byte offset.
 * @return int
 */
function uint32( string $bytes, int $offset ): int {
	$value = unpack( 'Vvalue', substr( $bytes, $offset, 4 ) );
	if ( false === $value ) {
		fail_validation( 'could not decode a 32-bit ZIP field.' );
	}
	return $value['value'];
}

if ( 2 !== $argc ) {
	fwrite( STDERR, "usage: php bin/validate-zip-manifest.php <plugin-zip>\n" );
	exit( 1 );
}

if ( PHP_INT_SIZE < 8 ) {
	fail_validation( 'a 64-bit PHP runtime is required for safe offset validation.' );
}

$archive_path = $argv[1];
$archive_size = filesize( $archive_path );
if ( false === $archive_size || $archive_size < 22 ) {
	fail_validation( 'archive is missing or too short.' );
}

$archive = fopen( $archive_path, 'rb' );
if ( false === $archive ) {
	fail_validation( 'archive could not be opened.' );
}

// EOCD is at most 65,557 bytes from EOF (22-byte record plus 65,535 comment).
$tail_length = min( $archive_size, 65557 );
$tail_offset = $archive_size - $tail_length;
$tail        = read_exact( $archive, $tail_offset, $tail_length, $archive_size );
$tail_size   = strlen( $tail );
$search_end  = $tail_size;
$eocd_index  = false;

while ( $search_end >= 4 ) {
	$candidate = strrpos( substr( $tail, 0, $search_end ), EOCD_SIGNATURE );
	if ( false === $candidate ) {
		break;
	}
	if ( $candidate + 22 <= $tail_size ) {
		$comment_length = uint16( $tail, $candidate + 20 );
		if ( $tail_size === $candidate + 22 + $comment_length ) {
			$eocd_index = $candidate;
			break;
		}
	}
	$search_end = $candidate;
}

if ( false === $eocd_index ) {
	fail_validation( 'end-of-central-directory record is missing or malformed.' );
}

$eocd          = substr( $tail, $eocd_index, 22 );
$eocd_offset   = $tail_offset + $eocd_index;
$disk_number   = uint16( $eocd, 4 );
$central_disk  = uint16( $eocd, 6 );
$entries_disk  = uint16( $eocd, 8 );
$entry_count   = uint16( $eocd, 10 );
$central_size  = uint32( $eocd, 12 );
$central_start = uint32( $eocd, 16 );

if ( 0 !== $disk_number || 0 !== $central_disk || $entries_disk !== $entry_count ) {
	fail_validation( 'multi-disk ZIP archives are not supported.' );
}
if ( 0xffff === $entry_count || 0xffffffff === $central_size || 0xffffffff === $central_start ) {
	fail_validation( 'ZIP64 archives are not supported.' );
}
if ( 0 === $entry_count ) {
	fail_validation( 'archive is empty.' );
}
if ( $central_start > $archive_size || $central_size > $archive_size - $central_start ) {
	fail_validation( 'central directory points outside the archive.' );
}
if ( $central_start + $central_size !== $eocd_offset ) {
	fail_validation( 'central directory boundary is inconsistent.' );
}

$cursor           = $central_start;
$central_end      = $central_start + $central_size;
$raw_names        = array();
$normalized_names = array();
$manifest         = array();

for ( $index = 0; $index < $entry_count; ++$index ) {
	$header = read_exact( $archive, $cursor, 46, $archive_size );
	if ( CENTRAL_SIGNATURE !== substr( $header, 0, 4 ) ) {
		fail_validation( "entry $index has an invalid central-directory signature." );
	}

	$made_by           = uint16( $header, 4 );
	$flags             = uint16( $header, 8 );
	$compression       = uint16( $header, 10 );
	$compressed_size   = uint32( $header, 20 );
	$uncompressed_size = uint32( $header, 24 );
	$name_length       = uint16( $header, 28 );
	$extra_length      = uint16( $header, 30 );
	$comment_length    = uint16( $header, 32 );
	$starting_disk     = uint16( $header, 34 );
	$external_attrs    = uint32( $header, 38 );
	$local_offset      = uint32( $header, 42 );
	$variable_length   = $name_length + $extra_length + $comment_length;

	if ( 0 === $name_length ) {
		fail_validation( "entry $index has no raw name." );
	}
	if ( 0xffff === $starting_disk || 0xffffffff === $compressed_size || 0xffffffff === $uncompressed_size || 0xffffffff === $local_offset ) {
		fail_validation( "entry $index requires unsupported ZIP64 fields." );
	}
	if ( 0 !== $starting_disk ) {
		fail_validation( "entry $index starts on another disk." );
	}
	// Reproducible release archives contain no alternate-name or ZIP64 extras.
	if ( 0 !== $extra_length || 0 !== $comment_length ) {
		fail_validation( "entry $index contains unsupported extra fields or comments." );
	}
	if ( $cursor + 46 > $central_end || $variable_length > $central_end - ( $cursor + 46 ) ) {
		fail_validation( "entry $index extends beyond the central directory." );
	}

	$name = read_exact( $archive, $cursor + 46, $name_length, $archive_size );
	if ( 1 !== preg_match( '//u', $name ) ) {
		fail_validation( "entry $index is not valid UTF-8." );
	}
	if ( 1 === preg_match( '/[\x00-\x1F\x7F]/', $name ) ) {
		fail_validation( "entry $index contains an ASCII control character." );
	}
	if ( false !== strpos( $name, '\\' ) ) {
		fail_validation( "entry contains a backslash: $name" );
	}
	if ( PACKAGE_ROOT !== $name && 0 !== strpos( $name, PACKAGE_ROOT ) ) {
		fail_validation( "entry is outside the package root: $name" );
	}
	if ( false !== strpos( $name, '//' ) ) {
		fail_validation( "entry has a repeated separator: $name" );
	}

	$normalized_name = rtrim( $name, '/' );
	$segments        = explode( '/', $normalized_name );
	if ( in_array( '.', $segments, true ) || in_array( '..', $segments, true ) ) {
		fail_validation( "entry has a dot segment: $name" );
	}
	if ( isset( $raw_names[ $name ] ) ) {
		fail_validation( "duplicate raw entry name: $name" );
	}
	if ( isset( $normalized_names[ $normalized_name ] ) ) {
		fail_validation( "normalized entry collision: $name" );
	}

	$host_system = ( $made_by >> 8 ) & 0xff;
	$file_type   = ( $external_attrs >> 16 ) & 0170000;
	if ( 0120000 === $file_type ) {
		fail_validation( "symbolic link entry: $name" );
	}
	if ( 3 === $host_system && 0 !== $file_type && 0100000 !== $file_type && 0040000 !== $file_type ) {
		fail_validation( "unsupported Unix file type: $name" );
	}
	if ( 3 === $host_system && 0040000 === $file_type && '/' !== substr( $name, -1 ) ) {
		fail_validation( "directory entry lacks a trailing separator: $name" );
	}
	if ( 3 === $host_system && 0100000 === $file_type && '/' === substr( $name, -1 ) ) {
		fail_validation( "regular-file entry has a trailing separator: $name" );
	}

	$local_header = read_exact( $archive, $local_offset, 30, $archive_size );
	if ( LOCAL_SIGNATURE !== substr( $local_header, 0, 4 ) ) {
		fail_validation( "entry $index has an invalid local-header signature." );
	}
	$local_flags       = uint16( $local_header, 6 );
	$local_compression = uint16( $local_header, 8 );
	$local_name_length = uint16( $local_header, 26 );
	$local_extra       = uint16( $local_header, 28 );
	if ( $local_flags !== $flags || $local_compression !== $compression || 0 !== $local_extra ) {
		fail_validation( "entry $index has inconsistent or unsupported local-header fields." );
	}
	$local_name = read_exact( $archive, $local_offset + 30, $local_name_length, $archive_size );
	if ( $local_name !== $name ) {
		fail_validation( "entry $index has different local and central raw names." );
	}
	$data_start = $local_offset + 30 + $local_name_length + $local_extra;
	if ( $data_start > $central_start || $compressed_size > $central_start - $data_start ) {
		fail_validation( "entry $index data overlaps the central directory." );
	}

	$raw_names[ $name ]                   = true;
	$normalized_names[ $normalized_name ] = true;
	$manifest[]                           = $name;
	$cursor                              += 46 + $variable_length;
}

if ( $cursor !== $central_end ) {
	fail_validation( 'central-directory entry count or size is inconsistent.' );
}

fclose( $archive );

foreach ( $manifest as $name ) {
	fwrite( STDOUT, $name . "\n" );
}
