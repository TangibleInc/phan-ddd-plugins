<?php
/**
 * Phan config for testing LayerRulesPlugin against a small fake codebase.
 */

return array(
	'target_php_version'              => '8.2',
	'directory_list'                  => array(
		'stubs',
		'src',
	),
	'exclude_analysis_directory_list' => array(
		'stubs',
	),
	'plugins'                         => array(
		__DIR__ . '/../../../plugins/LayerRulesPlugin.php',
	),
	'plugin_config'                   => array(
		'layer_rules' => array(
			'layers' => array(
				'domain'      => array(
					'namespaces'    => array( 'Acme\Shop\Domain' ),
					'rule'          => 'DDD-L1',
					'may_use'       => array(),
					'external_only' => array( 'TangibleDDD\Domain' ),
				),
				'application' => array(
					'namespaces' => array( 'Acme\Shop\Application' ),
					'rule'       => 'DDD-L4',
					'may_use'    => array( 'domain' ),
				),
				'infra'       => array(
					'namespaces' => array( 'Acme\Shop\Infra' ),
					'rule'       => 'infra',
					'may_use'    => array( 'domain', 'application' ),
				),
				'adapters'    => array(
					'namespaces'       => array( 'Acme\Shop\Api' ),
					'rule'             => 'DDD-L5',
					'may_use'          => array( 'domain', 'application' ),
					'deny'             => array( 'Acme\Shop\Domain\Repositories' => 'DDD-L5' ),
					'deny_subtypes_of' => array( 'TangibleDDD\Domain\Shared\Aggregate' => 'DDD-L5' ),
				),
			),
		),
	),
	'suppress_issue_types'            => array(
		// Noise unrelated to the plugin.
		'PhanUnreferencedClass',
		'PhanUnreferencedPublicMethod',
		'PhanUnreferencedClosure',
		'PhanReadOnlyPrivateProperty',
		'PhanUnusedVariableCaughtException',
		'PhanUnusedVariable',
		'PhanWriteOnlyPrivateProperty',
		'PhanUnreferencedPrivateProperty',
	),
);
