<?php

namespace TangibleDDD\Domain\Shared;

abstract class Entity {
	protected ?int $id;

	public function __construct( ?int $id ) {
		$this->id = $id;
	}

	public function get_id(): ?int {
		return $this->id;
	}
}

interface IRecordsDomainEvents {
	/**
	 * @param IDomainEvent $event
	 */
	public function event( $event ): void;

	/**
	 * @return array
	 */
	public function pull_events(): array;
}

abstract class Aggregate extends Entity implements IRecordsDomainEvents {
}
