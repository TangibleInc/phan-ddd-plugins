<?php

namespace TestFixtures;

use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\Shared\Aggregate;

class TestAggregate extends Aggregate {
	public function __construct() {
		parent::__construct( null );
	}

	public function event( $event ): void {
	}

	public function pull_events(): array {
		return array();
	}
}

class GoodRepository {

	public function __construct(
		private readonly EventsUnitOfWork $events
	) {
	}

	public function save( TestAggregate $aggregate ): void {
		// Persist somehow...
		$this->events->collect_from( $aggregate );
	}

	public function delete( TestAggregate $aggregate ): void {
		// Delete somehow...
		$this->events->collect_from( $aggregate );
	}
}
