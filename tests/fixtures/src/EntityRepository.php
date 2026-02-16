<?php

namespace TestFixtures;

use TangibleDDD\Domain\Shared\Entity;

class TestEntity extends Entity {
	public function __construct() {
		parent::__construct( null );
	}
}

class EntityRepository {

	public function save( TestEntity $entity ): void {
		// Entity, not Aggregate — should not trigger any rules.
	}
}
