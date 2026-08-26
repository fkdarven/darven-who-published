<?php
/**
 * Represents the confidence and evidence for a post publisher.
 *
 * @package Darven\WhoPublished
 * @subpackage Publisher
 */

namespace Darven\WhoPublished\Publisher;

use InvalidArgumentException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable publisher identity value object.
 */
final class PublisherIdentity {

	public const CONFIRMED = 'confirmed';
	public const ESTIMATED = 'estimated';
	public const UNKNOWN   = 'unknown';

	/**
	 * Publisher identity status.
	 *
	 * @var string
	 */
	private $status;

	/**
	 * Publisher user ID.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Estimation evidence source.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Creates an identity with validated internal values.
	 *
	 * @param string $status  Identity status.
	 * @param int    $user_id WordPress user ID.
	 * @param string $source  Estimation evidence source.
	 */
	private function __construct( string $status, int $user_id, string $source ) {
		$this->status  = $status;
		$this->user_id = $user_id;
		$this->source  = $source;
	}

	/**
	 * Creates a confirmed publisher identity.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return self
	 * @throws InvalidArgumentException When the user ID is not positive.
	 */
	public static function confirmed( int $user_id ): self {
		if ( $user_id <= 0 ) {
			throw new InvalidArgumentException( 'Confirmed publisher IDs must be positive.' );
		}

		return new self( self::CONFIRMED, $user_id, '' );
	}

	/**
	 * Creates an estimated publisher identity.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $source  Estimation evidence source.
	 * @return self
	 * @throws InvalidArgumentException When the user ID or source is invalid.
	 */
	public static function estimated( int $user_id, string $source ): self {
		if ( $user_id <= 0 ) {
			throw new InvalidArgumentException( 'Estimated publisher IDs must be positive.' );
		}

		if ( ! in_array( $source, self::estimation_sources(), true ) ) {
			throw new InvalidArgumentException( 'Estimated publisher source is not allowed.' );
		}

		return new self( self::ESTIMATED, $user_id, $source );
	}

	/**
	 * Creates an unknown publisher identity.
	 *
	 * @return self
	 */
	public static function unknown(): self {
		return new self( self::UNKNOWN, 0, '' );
	}

	/**
	 * Returns the current identity status.
	 *
	 * @return string
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Returns the publisher user ID, or zero when unknown.
	 *
	 * @return int
	 */
	public function user_id(): int {
		return $this->user_id;
	}

	/**
	 * Returns the estimation evidence source, or an empty string when not estimated.
	 *
	 * @return string
	 */
	public function source(): string {
		return $this->source;
	}

	/**
	 * Lists valid persisted estimation sources.
	 *
	 * @return string[]
	 */
	public static function estimation_sources(): array {
		return array( 'legacy', 'edit_last', 'latest_revision', 'post_author' );
	}
}
