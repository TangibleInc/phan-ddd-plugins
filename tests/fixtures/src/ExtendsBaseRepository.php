<?php

namespace TestFixtures;

use TangibleDDD\Application\Events\EventsUnitOfWork;
use TangibleDDD\Domain\Shared\Aggregate;
use TangibleDDD\Infra\Persistence\Shared\PersistsAggregatesRepository;

class ExtendsBaseRepository extends PersistsAggregatesRepository {

	public function __construct( EventsUnitOfWork $events ) {
		parent::__construct( $events );
	}

	protected function get_aggregate_class(): string {
		return Aggregate::class;
	}

	protected function persist( Aggregate $aggregate ): void {
		// Base class handles collect_from — no issues expected.
	}

	public function get_by_id( int $id ): ?Aggregate {
		return null;
	}
}
