<?php
/**
 * Per-crawler allow / block policy.
 *
 * @package WPASL
 */

namespace WPASL\Robots;

use WPASL\Settings;
use WPASL\Signals\ContentSignals;

/**
 * Resolves each crawler's policy: explicit override, otherwise the group default derived from the Content Signals.
 */
final class Policy {

	const ALLOW = 'allow';
	const BLOCK = 'block';

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Signals.
	 *
	 * @var ContentSignals
	 */
	private $signals;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param ContentSignals $signals  Signals.
	 */
	public function __construct( Settings $settings, ContentSignals $signals ) {
		$this->settings = $settings;
		$this->signals  = $signals;
	}

	/**
	 * Default policy of a group, derived from the signals.
	 *
	 * @param string $group Group id.
	 * @return string allow or block.
	 */
	public function default_for_group( $group ) {
		$values = $this->signals->values();
		switch ( $group ) {
			case Catalog::GROUP_TRAINING:
				return 'yes' === $values['ai-train'] ? self::ALLOW : self::BLOCK;
			case Catalog::GROUP_SEARCH:
				return 'yes' === $values['search'] ? self::ALLOW : self::BLOCK;
			case Catalog::GROUP_AGENT:
				return 'yes' === $values['ai-input'] ? self::ALLOW : self::BLOCK;
		}
		return self::ALLOW;
	}

	/**
	 * Explicit override for a crawler, if any.
	 *
	 * @param string $agent Agent token.
	 * @return string|null allow, block or null.
	 */
	public function override_for( $agent ) {
		$overrides = (array) $this->settings->get( 'crawler_overrides' );
		return isset( $overrides[ $agent ] ) && in_array( $overrides[ $agent ], array( self::ALLOW, self::BLOCK ), true ) ? $overrides[ $agent ] : null;
	}

	/**
	 * Effective policy for a crawler.
	 *
	 * @param string $agent Agent token.
	 * @return string allow or block.
	 */
	public function for_agent( $agent ) {
		$override = $this->override_for( $agent );
		if ( null !== $override ) {
			return $override;
		}
		$catalog = Catalog::all();
		$group   = isset( $catalog[ $agent ] ) ? $catalog[ $agent ]['group'] : Catalog::GROUP_TRAINING;
		return $this->default_for_group( $group );
	}

	/**
	 * Effective policy for every cataloged crawler.
	 *
	 * @return array<string, string> Agent token => allow|block.
	 */
	public function all() {
		$policies = array();
		foreach ( array_keys( Catalog::all() ) as $agent ) {
			$policies[ $agent ] = $this->for_agent( $agent );
		}
		return $policies;
	}

	/**
	 * Verdict for a full User-Agent header: allow, block, or null when it is not a cataloged AI crawler.
	 *
	 * @param string $user_agent User-Agent header.
	 * @return string|null
	 */
	public function verdict_for_user_agent( $user_agent ) {
		$agent = Catalog::match( $user_agent );
		return null === $agent ? null : $this->for_agent( $agent );
	}
}
