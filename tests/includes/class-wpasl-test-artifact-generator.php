<?php
/**
 * Artifact generator stub for tests.
 *
 * @package WPASL
 */

use WPASL\Generation\ArtifactGeneratorInterface;
use WPASL\Storage;

/**
 * Counts calls and writes a marker file.
 */
class WPASL_Test_Artifact_Generator implements ArtifactGeneratorInterface {

	/**
	 * Number of generate() calls.
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'stub';
	}

	/**
	 * {@inheritDoc}
	 */
	public function generate( Storage $storage ) {
		++$this->calls;
		$storage->write( 'stub.txt', (string) $this->calls );
	}
}
