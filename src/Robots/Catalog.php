<?php
/**
 * Catalog of AI crawlers.
 *
 * @package WPASL
 */

namespace WPASL\Robots;

/**
 * Known AI user-agents grouped by purpose. Extensible through the wpasl_crawler_catalog filter.
 */
final class Catalog {

	const GROUP_TRAINING = 'training';
	const GROUP_SEARCH   = 'search';
	const GROUP_AGENT    = 'agent';

	/**
	 * Group order and labels.
	 *
	 * @return array<string, string>
	 */
	public static function groups() {
		return array(
			self::GROUP_TRAINING => __( 'Training crawlers', 'wp-agent-support-layer' ),
			self::GROUP_SEARCH   => __( 'AI search crawlers', 'wp-agent-support-layer' ),
			self::GROUP_AGENT    => __( 'On-demand agents', 'wp-agent-support-layer' ),
		);
	}

	/**
	 * Group descriptions.
	 *
	 * @return array<string, string>
	 */
	public static function group_descriptions() {
		return array(
			self::GROUP_TRAINING => __( 'Collect content to train or fine-tune models. Follows the "AI training" signal by default.', 'wp-agent-support-layer' ),
			self::GROUP_SEARCH   => __( 'Index content for AI-powered search and answer engines. Follows the "Search" signal by default.', 'wp-agent-support-layer' ),
			self::GROUP_AGENT    => __( 'Fetch pages on behalf of a user during a conversation. Follows the "AI input" signal by default.', 'wp-agent-support-layer' ),
		);
	}

	/**
	 * Built-in crawlers.
	 *
	 * @return array<int, array{agent:string, vendor:string, group:string, docs:string}>
	 */
	private static function builtin() {
		return array(
			// Training.
			array( 'agent' => 'GPTBot', 'vendor' => 'OpenAI', 'group' => self::GROUP_TRAINING, 'docs' => 'https://platform.openai.com/docs/bots' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'ClaudeBot', 'vendor' => 'Anthropic', 'group' => self::GROUP_TRAINING, 'docs' => 'https://support.anthropic.com/en/articles/8896518' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'anthropic-ai', 'vendor' => 'Anthropic', 'group' => self::GROUP_TRAINING, 'docs' => 'https://support.anthropic.com/en/articles/8896518' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'Google-Extended', 'vendor' => 'Google (Gemini)', 'group' => self::GROUP_TRAINING, 'docs' => 'https://developers.google.com/search/docs/crawling-indexing/overview-google-crawlers' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'Applebot-Extended', 'vendor' => 'Apple', 'group' => self::GROUP_TRAINING, 'docs' => 'https://support.apple.com/en-us/119829' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'CCBot', 'vendor' => 'Common Crawl', 'group' => self::GROUP_TRAINING, 'docs' => 'https://commoncrawl.org/ccbot' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'Bytespider', 'vendor' => 'ByteDance', 'group' => self::GROUP_TRAINING, 'docs' => '' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'meta-externalagent', 'vendor' => 'Meta', 'group' => self::GROUP_TRAINING, 'docs' => 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'Amazonbot', 'vendor' => 'Amazon', 'group' => self::GROUP_TRAINING, 'docs' => 'https://developer.amazon.com/amazonbot' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'cohere-ai', 'vendor' => 'Cohere', 'group' => self::GROUP_TRAINING, 'docs' => 'https://docs.cohere.com/docs/cohere-web-crawlers' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'cohere-training-data-crawler', 'vendor' => 'Cohere', 'group' => self::GROUP_TRAINING, 'docs' => 'https://docs.cohere.com/docs/cohere-web-crawlers' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'Diffbot', 'vendor' => 'Diffbot', 'group' => self::GROUP_TRAINING, 'docs' => 'https://docs.diffbot.com/docs/diffbot-crawler' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'omgili', 'vendor' => 'Webz.io', 'group' => self::GROUP_TRAINING, 'docs' => 'https://webz.io/bot.html' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'PetalBot', 'vendor' => 'Huawei', 'group' => self::GROUP_TRAINING, 'docs' => 'https://aspiegel.com/petalbot' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			// AI search.
			array( 'agent' => 'OAI-SearchBot', 'vendor' => 'OpenAI', 'group' => self::GROUP_SEARCH, 'docs' => 'https://platform.openai.com/docs/bots' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'PerplexityBot', 'vendor' => 'Perplexity', 'group' => self::GROUP_SEARCH, 'docs' => 'https://docs.perplexity.ai/guides/bots' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'Claude-SearchBot', 'vendor' => 'Anthropic', 'group' => self::GROUP_SEARCH, 'docs' => 'https://support.anthropic.com/en/articles/8896518' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'DuckAssistBot', 'vendor' => 'DuckDuckGo', 'group' => self::GROUP_SEARCH, 'docs' => 'https://duckduckgo.com/duckduckgo-help-pages/results/duckassistbot' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'YouBot', 'vendor' => 'You.com', 'group' => self::GROUP_SEARCH, 'docs' => 'https://about.you.com/youbot/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			// On-demand agents.
			array( 'agent' => 'ChatGPT-User', 'vendor' => 'OpenAI', 'group' => self::GROUP_AGENT, 'docs' => 'https://platform.openai.com/docs/bots' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'Claude-User', 'vendor' => 'Anthropic', 'group' => self::GROUP_AGENT, 'docs' => 'https://support.anthropic.com/en/articles/8896518' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'Perplexity-User', 'vendor' => 'Perplexity', 'group' => self::GROUP_AGENT, 'docs' => 'https://docs.perplexity.ai/guides/bots' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'MistralAI-User', 'vendor' => 'Mistral AI', 'group' => self::GROUP_AGENT, 'docs' => 'https://docs.mistral.ai/robots/' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'agent' => 'meta-externalfetcher', 'vendor' => 'Meta', 'group' => self::GROUP_AGENT, 'docs' => 'https://developers.facebook.com/docs/sharing/webmasters/web-crawlers' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
	}

	/**
	 * All crawlers, keyed by user-agent token, after the filter.
	 *
	 * @return array<string, array{agent:string, vendor:string, group:string, docs:string}>
	 */
	public static function all() {
		/**
		 * Filters the AI crawler catalog.
		 *
		 * @param array<int, array{agent:string, vendor:string, group:string, docs:string}> $crawlers Crawlers.
		 */
		$crawlers = apply_filters( 'wpasl_crawler_catalog', self::builtin() );

		$keyed = array();
		foreach ( (array) $crawlers as $crawler ) {
			if ( empty( $crawler['agent'] ) || ! is_string( $crawler['agent'] ) ) {
				continue;
			}
			$agent = trim( $crawler['agent'] );
			if ( '' === $agent || preg_match( '/[\s#:]/', $agent ) ) {
				continue;
			}
			$group           = isset( $crawler['group'] ) && isset( self::groups()[ $crawler['group'] ] ) ? $crawler['group'] : self::GROUP_TRAINING;
			$keyed[ $agent ] = array(
				'agent'  => $agent,
				'vendor' => isset( $crawler['vendor'] ) ? (string) $crawler['vendor'] : '',
				'group'  => $group,
				'docs'   => isset( $crawler['docs'] ) ? (string) $crawler['docs'] : '',
			);
		}
		return $keyed;
	}

	/**
	 * Crawlers of one group.
	 *
	 * @param string $group Group id.
	 * @return array<string, array{agent:string, vendor:string, group:string, docs:string}>
	 */
	public static function by_group( $group ) {
		return array_filter(
			self::all(),
			static function ( $crawler ) use ( $group ) {
				return $crawler['group'] === $group;
			}
		);
	}

	/**
	 * Whether a user-agent header string belongs to a cataloged crawler.
	 *
	 * @param string $user_agent Full User-Agent header.
	 * @return string|null Catalog agent token or null.
	 */
	public static function match( $user_agent ) {
		$user_agent = strtolower( (string) $user_agent );
		foreach ( array_keys( self::all() ) as $agent ) {
			if ( false !== strpos( $user_agent, strtolower( $agent ) ) ) {
				return $agent;
			}
		}
		return null;
	}
}
