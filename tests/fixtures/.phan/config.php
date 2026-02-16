<?php
/**
 * Minimal Phan config for testing the DDD plugins.
 */

return array(
	'target_php_version'       => '8.2',
	'directory_list'           => array(
		'stubs',
		'src',
	),
	'exclude_analysis_directory_list' => array(
		'stubs',
	),
	'plugins'                  => array(
		__DIR__ . '/../../../plugins/DDDAggregateEventCollectionPlugin.php',
	),
	'suppress_issue_types'     => array(
		// Suppress noise unrelated to our plugin.
		'PhanUnreferencedClass',
		'PhanReadOnlyPrivateProperty',
		'PhanUnreferencedPublicMethod',
		'PhanUnreferencedPublicClassConstant',
	),
);
