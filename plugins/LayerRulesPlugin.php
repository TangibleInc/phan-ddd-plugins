<?php
/**
 * Phan plugin enforcing layer dependency rules.
 *
 * A codebase is split into layers by namespace, and each layer may reference
 * only the layers it is allowed to. Every place a class can be named counts
 * as a reference: extends and implements, used traits, property, parameter
 * and return types (including PHPDoc types such as array<int, Foo>), new,
 * static calls and properties, class constants, instanceof, catch, and
 * Foo::class. Fully qualified names written inline count the same as names
 * imported with `use`.
 *
 * Issue type: PhanPluginLayerViolation. The message carries the rule ID
 * configured for the layer, such as DDD-L1, so a report reads the same as
 * the rule document it enforces.
 *
 * Configuration lives in the Phan config under plugin_config.layer_rules:
 *
 *   'plugin_config' => [
 *     'layer_rules' => [
 *       'layers' => [
 *         'domain' => [
 *           'namespaces'    => ['Acme\Shop\Domain'],
 *           'rule'          => 'DDD-L1',
 *           'may_use'       => [],
 *           // Optional: the only code outside every layer this layer may
 *           // reference, besides PHP's own classes.
 *           'external_only' => ['TangibleDDD\Domain'],
 *         ],
 *         'adapters' => [
 *           'namespaces'       => ['Acme\Shop\Api'],
 *           'rule'             => 'DDD-L5',
 *           'may_use'          => ['domain', 'application'],
 *           // Optional: namespaces forbidden even inside an allowed layer.
 *           'deny'             => ['Acme\Shop\Domain\Repositories' => 'DDD-L5'],
 *           // Optional: classes forbidden by what they extend or implement.
 *           'deny_subtypes_of' => ['TangibleDDD\Domain\Shared\Aggregate' => 'DDD-L5'],
 *         ],
 *       ],
 *     ],
 *   ],
 *
 * A layer may always reference itself. Code outside every layer is never
 * checked. Without configuration, the plugin reports nothing.
 *
 * @package tangible/phan-ddd-plugins
 */

use Phan\AST\UnionTypeVisitor;
use Phan\CodeBase;
use Phan\Config;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Method;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\Type;
use Phan\Language\Type\GenericArrayInterface;
use Phan\Language\UnionType;
use Phan\PluginV3;
use Phan\PluginV3\AnalyzeClassCapability;
use Phan\PluginV3\AnalyzeMethodCapability;
use Phan\PluginV3\PluginAwarePostAnalysisVisitor;
use Phan\PluginV3\PostAnalyzeNodeCapability;
use ast\Node;

class LayerRulesPlugin extends PluginV3 implements AnalyzeClassCapability, AnalyzeMethodCapability, PostAnalyzeNodeCapability {

	public const ISSUE_TYPE = 'PhanPluginLayerViolation';

	/** @var ?array<string, array{namespaces: list<string>, rule: string, may_use: list<string>, external_only: ?list<string>, deny: array<string, string>, deny_subtypes_of: array<string, string>}> */
	private static $layers = null;

	/** @var array<string, true> Issues already emitted, so one reference is reported once. */
	private static $emitted = array();

	public static function getPostAnalyzeNodeVisitorClassName(): string {
		return LayerRulesVisitor::class;
	}

	/**
	 * extends, implements, traits, and property types.
	 */
	public function analyzeClass( CodeBase $code_base, Clazz $class ): void {
		$from    = (string) $class->getFQSEN();
		$context = $class->getContext();

		if ( $class->hasParentType() ) {
			self::check( $code_base, $context, $from, $class->getParentClassFQSEN(), 'extends' );
		}
		foreach ( $class->getInterfaceFQSENList() as $interface ) {
			self::check( $code_base, $context, $from, $interface, 'implements' );
		}
		foreach ( $class->getTraitFQSENList() as $trait ) {
			self::check( $code_base, $context, $from, $trait, 'uses trait' );
		}
		foreach ( $class->getPropertyMap( $code_base ) as $property ) {
			if ( (string) $property->getDefiningFQSEN()->getFullyQualifiedClassName() !== $from ) {
				continue;
			}
			foreach ( self::classes_in( $property->getUnionType() ) as $target ) {
				self::check( $code_base, $property->getContext(), $from, $target, 'property type' );
			}
		}
	}

	/**
	 * Parameter and return types, declared or from PHPDoc.
	 */
	public function analyzeMethod( CodeBase $code_base, Method $method ): void {
		if ( $method->isFromPHPDoc() ) {
			return;
		}
		$class = (string) $method->getFQSEN()->getFullyQualifiedClassName();
		if ( (string) $method->getDefiningFQSEN()->getFullyQualifiedClassName() !== $class ) {
			return;
		}

		foreach ( $method->getParameterList() as $parameter ) {
			foreach ( self::classes_in( $parameter->getUnionType() ) as $target ) {
				self::check( $code_base, $method->getContext(), $class, $target, 'parameter type' );
			}
		}
		foreach ( self::classes_in( $method->getUnionType() ) as $target ) {
			self::check( $code_base, $method->getContext(), $class, $target, 'return type' );
		}
	}

	/**
	 * Check one reference from a class or namespace to a class.
	 *
	 * @param string $from The referencing class, or `namespace\` for code outside a class.
	 */
	public static function check( CodeBase $code_base, Context $context, string $from, FullyQualifiedClassName $target, string $how ): void {
		$layers = self::layers();
		if ( $layers === array() ) {
			return;
		}

		$from_layer = self::layer_of( $from );
		if ( $from_layer === null ) {
			return;
		}

		$to = (string) $target;
		if ( $to === $from ) {
			return;
		}

		$config   = $layers[ $from_layer ];
		$to_layer = self::layer_of( $to );

		foreach ( $config['deny'] as $prefix => $rule ) {
			if ( self::in_namespace( $to, $prefix ) ) {
				self::emit( $code_base, $context, $from, $from_layer, $to, $how, $rule, 'is denied here' );
				return;
			}
		}

		if ( $config['deny_subtypes_of'] !== array() && $code_base->hasClassWithFQSEN( $target ) ) {
			$ancestors = $target->asType()->asExpandedTypes( $code_base );
			foreach ( $config['deny_subtypes_of'] as $parent => $rule ) {
				if ( $ancestors->hasType( FullyQualifiedClassName::fromFullyQualifiedString( $parent )->asType() ) ) {
					self::emit( $code_base, $context, $from, $from_layer, $to, $how, $rule, 'is a ' . $parent );
					return;
				}
			}
		}

		if ( $to_layer !== null ) {
			if ( $to_layer !== $from_layer && ! in_array( $to_layer, $config['may_use'], true ) ) {
				self::emit( $code_base, $context, $from, $from_layer, $to, $how, $config['rule'], 'is in layer ' . $to_layer );
			}
			return;
		}

		if ( $config['external_only'] !== null && ! self::external_allowed( $code_base, $target, $config['external_only'] ) ) {
			self::emit( $code_base, $context, $from, $from_layer, $to, $how, $config['rule'], 'is outside the code this layer may use' );
		}
	}

	/**
	 * Classes named by a union type, including array element types and
	 * template parameters.
	 *
	 * @return list<FullyQualifiedClassName>
	 */
	public static function classes_in( UnionType $union_type ): array {
		$classes = array();
		foreach ( $union_type->getTypeSet() as $type ) {
			self::collect( $type, $classes );
		}
		return $classes;
	}

	/**
	 * @param list<FullyQualifiedClassName> $classes
	 */
	private static function collect( Type $type, array &$classes ): void {
		if ( $type instanceof GenericArrayInterface ) {
			foreach ( $type->genericArrayElementUnionType()->getTypeSet() as $element ) {
				self::collect( $element, $classes );
			}
			return;
		}
		if ( $type->isObjectWithKnownFQSEN() ) {
			$classes[] = FullyQualifiedClassName::fromType( $type );
		}
		foreach ( $type->getTemplateParameterTypeList() as $parameter ) {
			foreach ( $parameter->getTypeSet() as $inner ) {
				self::collect( $inner, $classes );
			}
		}
	}

	/**
	 * @param list<string> $allowed
	 */
	private static function external_allowed( CodeBase $code_base, FullyQualifiedClassName $target, array $allowed ): bool {
		$name = (string) $target;
		foreach ( $allowed as $prefix ) {
			if ( self::in_namespace( $name, $prefix ) ) {
				return true;
			}
		}
		if ( ! $code_base->hasClassWithFQSEN( $target ) ) {
			// Unknown classes are Phan's own issue to report.
			return true;
		}
		return $code_base->getClassByFQSEN( $target )->isPHPInternal();
	}

	private static function emit( CodeBase $code_base, Context $context, string $from, string $from_layer, string $to, string $how, string $rule, string $why ): void {
		$key = $context->getFile() . ':' . $context->getLineNumberStart() . ':' . $to . ':' . $rule;
		if ( isset( self::$emitted[ $key ] ) ) {
			return;
		}
		self::$emitted[ $key ] = true;

		self::emitPluginIssue(
			$code_base,
			$context,
			self::ISSUE_TYPE,
			'{STRING_LITERAL}: {CLASS} (layer {STRING_LITERAL}) references {CLASS} by {DETAILS}, which {DETAILS}',
			array( $rule, ltrim( $from, '\\' ), $from_layer, ltrim( $to, '\\' ), $how, $why ),
			Issue::SEVERITY_NORMAL,
			Issue::REMEDIATION_B,
			Issue::TYPE_ID_UNKNOWN
		);
	}

	/**
	 * The layer a class or namespace belongs to, by the longest matching namespace.
	 */
	private static function layer_of( string $name ): ?string {
		$best        = null;
		$best_length = -1;
		foreach ( self::layers() as $layer => $config ) {
			foreach ( $config['namespaces'] as $namespace ) {
				if ( self::in_namespace( $name, $namespace ) && strlen( $namespace ) > $best_length ) {
					$best        = $layer;
					$best_length = strlen( $namespace );
				}
			}
		}
		return $best;
	}

	private static function in_namespace( string $name, string $namespace ): bool {
		return str_starts_with( '\\' . ltrim( $name, '\\' ), '\\' . trim( $namespace, '\\' ) . '\\' );
	}

	/**
	 * @return array<string, array{namespaces: list<string>, rule: string, may_use: list<string>, external_only: ?list<string>, deny: array<string, string>, deny_subtypes_of: array<string, string>}>
	 */
	private static function layers(): array {
		if ( self::$layers !== null ) {
			return self::$layers;
		}
		$configured   = Config::getValue( 'plugin_config' )['layer_rules']['layers'] ?? array();
		self::$layers = array();
		foreach ( $configured as $name => $layer ) {
			self::$layers[ (string) $name ] = array(
				'namespaces'       => array_values( $layer['namespaces'] ?? array() ),
				'rule'             => (string) ( $layer['rule'] ?? 'layer rule' ),
				'may_use'          => array_values( $layer['may_use'] ?? array() ),
				'external_only'    => isset( $layer['external_only'] ) ? array_values( $layer['external_only'] ) : null,
				'deny'             => $layer['deny'] ?? array(),
				'deny_subtypes_of' => $layer['deny_subtypes_of'] ?? array(),
			);
		}
		return self::$layers;
	}
}

/**
 * Class references inside function and method bodies.
 */
class LayerRulesVisitor extends PluginAwarePostAnalysisVisitor {

	public function visitNew( Node $node ): void {
		$this->check_class_node( $node->children['class'] ?? null, 'new' );
	}

	public function visitStaticCall( Node $node ): void {
		$this->check_class_node( $node->children['class'] ?? null, 'static call' );
	}

	public function visitStaticProp( Node $node ): void {
		$this->check_class_node( $node->children['class'] ?? null, 'static property' );
	}

	public function visitClassConst( Node $node ): void {
		$this->check_class_node( $node->children['class'] ?? null, 'class constant' );
	}

	public function visitClassName( Node $node ): void {
		$this->check_class_node( $node->children['class'] ?? null, '::class' );
	}

	public function visitInstanceof( Node $node ): void {
		$this->check_class_node( $node->children['class'] ?? null, 'instanceof' );
	}

	public function visitCatch( Node $node ): void {
		$list = $node->children['class'] ?? null;
		if ( $list instanceof Node ) {
			foreach ( $list->children as $name ) {
				$this->check_class_node( $name, 'catch' );
			}
		}
	}

	/**
	 * @param mixed $class_node
	 */
	private function check_class_node( $class_node, string $how ): void {
		if ( ! $class_node instanceof Node || $class_node->kind !== \ast\AST_NAME ) {
			return;
		}
		try {
			$union_type = UnionTypeVisitor::unionTypeFromClassNode( $this->code_base, $this->context, $class_node );
		} catch ( \Throwable $e ) {
			return;
		}

		$from = $this->context->isInClassScope()
			? (string) $this->context->getClassFQSEN()
			: rtrim( $this->context->getNamespace(), '\\' ) . '\\';

		foreach ( LayerRulesPlugin::classes_in( $union_type ) as $target ) {
			LayerRulesPlugin::check( $this->code_base, $this->context, $from, $target, $how );
		}
	}
}

return new LayerRulesPlugin();
