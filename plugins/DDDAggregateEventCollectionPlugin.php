<?php
/**
 * Phan plugin enforcing DDD domain event collection practices.
 *
 * Rule 1 (PhanPluginDDDMissingEventCollection):
 *   Repository methods that accept an Aggregate must call collect_from().
 *
 * Rule 2 (PhanPluginDDDMissingEventsUnitOfWork):
 *   Repository classes that persist aggregates must accept EventsUnitOfWork
 *   in their constructor.
 *
 * @package tangible/phan-ddd-plugins
 */

use Phan\CodeBase;
use Phan\Issue;
use Phan\Language\Element\Clazz;
use Phan\Language\Element\Method;
use Phan\Language\FQSEN\FullyQualifiedClassName;
use Phan\Language\Type;
use Phan\Language\UnionType;
use Phan\PluginV3;
use Phan\PluginV3\AnalyzeClassCapability;
use ast\Node;

class DDDAggregateEventCollectionPlugin extends PluginV3 implements AnalyzeClassCapability {

	private const AGGREGATE_FQSEN = '\\TangibleDDD\\Domain\\Shared\\Aggregate';
	private const EVENTS_UOW_FQSEN = '\\TangibleDDD\\Application\\Events\\EventsUnitOfWork';
	private const PERSISTS_AGGREGATES_FQSEN = '\\TangibleDDD\\Infra\\Persistence\\Shared\\PersistsAggregatesRepository';
	private const METHODS_TO_CHECK = array( 'save', 'delete' );

	public function getAnalyzeClassCapabilityList(): array {
		return array( $this );
	}

	public function analyzeClass( CodeBase $code_base, Clazz $class ): void {
		if ( $class->isInterface() || $class->isTrait() || $class->isAbstract() ) {
			return;
		}

		if ( $this->extends_persists_aggregates_repository( $code_base, $class ) ) {
			return;
		}

		$aggregate_methods = $this->find_aggregate_methods( $code_base, $class );
		if ( empty( $aggregate_methods ) ) {
			return;
		}

		// Rule 1: Each aggregate method must call collect_from().
		foreach ( $aggregate_methods as list( $method, $aggregate_type_name ) ) {
			$node = $method->getNode();
			if ( $node === null ) {
				continue;
			}

			$stmts = $node->children['stmts'] ?? null;
			if ( $stmts === null ) {
				continue;
			}

			if ( ! $this->ast_contains_collect_from( $stmts ) ) {
				$this->emitIssue(
					$code_base,
					$method->getContext(),
					'PhanPluginDDDMissingEventCollection',
					'Repository method {METHOD} accepts aggregate {CLASS} but does not call collect_from() to collect domain events',
					array( $method->getName(), $aggregate_type_name ),
					Issue::SEVERITY_NORMAL,
					Issue::REMEDIATION_B,
					Issue::TYPE_ID_UNKNOWN
				);
			}
		}

		// Rule 2: Class must accept EventsUnitOfWork in its constructor.
		if ( ! $this->constructor_has_events_uow( $code_base, $class ) ) {
			$first_method = $aggregate_methods[0][0];
			$aggregate_type_name = $aggregate_methods[0][1];
			$this->emitIssue(
				$code_base,
				$class->getContext(),
				'PhanPluginDDDMissingEventsUnitOfWork',
				'Repository {CLASS} persists aggregate {CLASS} but does not accept EventsUnitOfWork in its constructor',
				array( $class->getFQSEN()->__toString(), $aggregate_type_name ),
				Issue::SEVERITY_NORMAL,
				Issue::REMEDIATION_B,
				Issue::TYPE_ID_UNKNOWN
			);
		}
	}

	/**
	 * Check if a class extends PersistsAggregatesRepository.
	 */
	private function extends_persists_aggregates_repository( CodeBase $code_base, Clazz $class ): bool {
		return $this->type_extends(
			$code_base,
			$class,
			self::PERSISTS_AGGREGATES_FQSEN
		);
	}

	/**
	 * Find save()/delete() methods defined on this class whose first parameter extends Aggregate.
	 *
	 * @return array<array{Method, string}> Each entry is [method, aggregate FQSEN string].
	 */
	private function find_aggregate_methods( CodeBase $code_base, Clazz $class ): array {
		$result = array();

		foreach ( self::METHODS_TO_CHECK as $method_name ) {
			if ( ! $class->hasMethodWithName( $code_base, $method_name, true ) ) {
				continue;
			}

			$method = $class->getMethodByName( $code_base, $method_name );

			// Only check methods defined on this class, not inherited ones.
			if ( $method->getDefiningFQSEN()->getFullyQualifiedClassName()->__toString()
				!== $class->getFQSEN()->__toString()
			) {
				continue;
			}

			$params = $method->getParameterList();
			if ( empty( $params ) ) {
				continue;
			}

			$first_param_type = $params[0]->getUnionType();
			$aggregate_fqsen = $this->get_aggregate_type( $code_base, $first_param_type );
			if ( $aggregate_fqsen !== null ) {
				$result[] = array( $method, $aggregate_fqsen );
			}
		}

		return $result;
	}

	/**
	 * Check if a union type contains a type that extends Aggregate.
	 *
	 * @return string|null The FQSEN string of the aggregate type, or null.
	 */
	private function get_aggregate_type( CodeBase $code_base, UnionType $union_type ): ?string {
		foreach ( $union_type->getTypeSet() as $type ) {
			if ( $type->isNullableLabeled() || ! $type->isObjectWithKnownFQSEN() ) {
				continue;
			}

			$fqsen = FullyQualifiedClassName::fromType( $type );
			if ( ! $code_base->hasClassWithFQSEN( $fqsen ) ) {
				continue;
			}

			$param_class = $code_base->getClassByFQSEN( $fqsen );
			if ( $this->type_extends( $code_base, $param_class, self::AGGREGATE_FQSEN ) ) {
				return $fqsen->__toString();
			}
		}
		return null;
	}

	/**
	 * Walk the parent chain to check if a class extends a given FQSEN.
	 */
	private function type_extends( CodeBase $code_base, Clazz $class, string $target_fqsen ): bool {
		$current = $class;
		while ( true ) {
			if ( $current->getFQSEN()->__toString() === $target_fqsen ) {
				return true;
			}

			if ( ! $current->hasParentType() ) {
				return false;
			}

			$parent_fqsen = $current->getParentClassFQSEN();
			if ( ! $code_base->hasClassWithFQSEN( $parent_fqsen ) ) {
				return false;
			}

			$current = $code_base->getClassByFQSEN( $parent_fqsen );
		}
	}

	/**
	 * Check if the constructor has an EventsUnitOfWork parameter.
	 */
	private function constructor_has_events_uow( CodeBase $code_base, Clazz $class ): bool {
		if ( ! $class->hasMethodWithName( $code_base, '__construct', true ) ) {
			return false;
		}

		$constructor = $class->getMethodByName( $code_base, '__construct' );
		$uow_type = Type::fromFullyQualifiedString( self::EVENTS_UOW_FQSEN );

		foreach ( $constructor->getParameterList() as $param ) {
			if ( $param->getUnionType()->hasType( $uow_type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively check if an AST node contains a collect_from() method call.
	 *
	 * @param Node|mixed $node
	 */
	private function ast_contains_collect_from( $node ): bool {
		if ( ! ( $node instanceof Node ) ) {
			return false;
		}

		if (
			( $node->kind === ast\AST_METHOD_CALL || $node->kind === ast\AST_NULLSAFE_METHOD_CALL )
			&& ( $node->children['method'] ?? null ) === 'collect_from'
		) {
			return true;
		}

		foreach ( $node->children as $child ) {
			if ( $this->ast_contains_collect_from( $child ) ) {
				return true;
			}
		}

		return false;
	}
}

return new DDDAggregateEventCollectionPlugin();
