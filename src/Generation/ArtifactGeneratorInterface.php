<?php
/**
 * Site-wide artifact generator contract.
 *
 * @package WPASL
 */

namespace WPASL\Generation;

use WPASL\Storage;

/**
 * Produces site-wide files (llms.txt, manifests) at the end of a generation cycle.
 */
interface ArtifactGeneratorInterface {

	/**
	 * Identifier used in status output.
	 *
	 * @return string
	 */
	public function id();

	/**
	 * Writes the artifact(s) to storage.
	 *
	 * @param Storage $storage Storage.
	 * @return void
	 */
	public function generate( Storage $storage );
}
