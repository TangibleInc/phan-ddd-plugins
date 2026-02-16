<?php

namespace TestFixtures;

use TangibleDDD\Application\Events\EventsUnitOfWork;

class MissingCollectFromRepository {

	public function __construct(
		private readonly EventsUnitOfWork $events
	) {
	}

	public function save( TestAggregate $aggregate ): void {
		// Persists but forgets to collect events!
	}
}
