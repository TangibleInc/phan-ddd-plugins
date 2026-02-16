<?php

namespace TestFixtures;

class MissingUoWRepository {

	public function __construct() {
	}

	public function save( TestAggregate $aggregate ): void {
		// Has collect_from call somehow but no UoW in constructor.
		// This is contrived but tests Rule 2 in isolation.
		$events = new \TangibleDDD\Application\Events\EventsUnitOfWork();
		$events->collect_from( $aggregate );
	}
}
