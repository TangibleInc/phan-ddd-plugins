<?php

namespace TangibleDDD\Infra\Persistence\Shared;

use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\Shared\Aggregate;

abstract class PersistsAggregatesRepository {

	public function __construct(
		protected readonly EventsUnitOfWork $events
	) {
	}

	/**
	 * @param Aggregate $aggregate
	 */
	abstract protected function persist( Aggregate $aggregate ): void;

	/**
	 * @param Aggregate $aggregate
	 */
	public function save( Aggregate $aggregate ): void {
		$this->persist( $aggregate );
		$this->events->collect_from( $aggregate );
	}
}
