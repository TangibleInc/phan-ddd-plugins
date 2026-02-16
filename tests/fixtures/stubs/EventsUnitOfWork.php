<?php

namespace TangibleDDD\Application\Events;

use TangibleDDD\Domain\Shared\IRecordsDomainEvents;

class EventsUnitOfWork {
	/**
	 * @param IRecordsDomainEvents $aggregate
	 */
	public function collect_from( IRecordsDomainEvents $aggregate ): void {
	}
}
