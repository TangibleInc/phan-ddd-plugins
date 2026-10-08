<?php
/**
 * Adapter fixtures.
 */

namespace Acme\Shop\Api {
	use Acme\Shop\Application\Dto\OrderView;
	use Acme\Shop\Application\GetOrderHandler;
	use Acme\Shop\Domain\Enums\Status;
	use Acme\Shop\Domain\Order;
	use Acme\Shop\Domain\Repositories\IOrderRepository;

	final class OrderResponse {
	}

	// GOOD: application handlers and read models, and a domain enum.
	final class GoodController {
		public function __construct( private GetOrderHandler $get_order ) {
		}

		public function show( int $id ): ?OrderView {
			return Status::Open === Status::from( 'open' ) ? $this->get_order->handle( $id ) : null;
		}
	}

	final class RepositoryController {
		// BAD (DDD-L5, deny): an adapter holding a repository.
		public function __construct( private IOrderRepository $orders ) {
		}
	}

	final class AggregateController {
		// BAD (DDD-L5, deny_subtypes_of): an adapter handling an aggregate.
		public function close( Order $order ): void {
		}
	}

	final class InfraController {
		public function save(): void {
			try {
				$saved = true;
			} catch ( \Acme\Shop\Infra\DbException $e ) {
				// BAD (DDD-L5, layer): an adapter reaching into infrastructure.
			}
		}
	}
}
