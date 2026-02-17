<?php

namespace TangibleDDD\Infra\Persistence\Shared;

use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\Shared\Aggregate;
use TangibleDDD\Domain\Exceptions\TypeMismatchException;

interface IPersistsAggregates {
	/**
	 * @param Aggregate $aggregate
	 */
	public function save( Aggregate $aggregate ): void;

	/**
	 * @param int $id
	 * @return Aggregate|null
	 */
	public function get_by_id( int $id ): ?Aggregate;
}

abstract class PersistsAggregatesRepository implements IPersistsAggregates {

	public function __construct(
		protected readonly EventsUnitOfWork $events
	) {
	}

	/**
	 * @return class-string<Aggregate>
	 */
	abstract protected function get_aggregate_class(): string;

	/**
	 * @param Aggregate $aggregate
	 */
	abstract protected function persist( Aggregate $aggregate ): void;

	/**
	 * @param Aggregate $aggregate
	 * @throws TypeMismatchException
	 */
	public function save( Aggregate $aggregate ): void {
		$this->persist( $aggregate );
		$this->events->collect_from( $aggregate );
	}
}
