<?php

namespace Telepedia\Extensions\TableProgressTracking;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\User\UserIdentity;
use Psr\Log\LoggerInterface;
use StatusValue;
use Wikimedia\Rdbms\IConnectionProvider;

class ProgressService {

	public const CONSTRUCTOR_OPTIONS = [];

	public function __construct(
		private readonly ServiceOptions $options,
		private readonly LoggerInterface $logger,
		private readonly IConnectionProvider $connectionProvider
	) {
		$options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	/**
	 * Get the progress of a user on a specific article and table.
	 *
	 * @param int $articleId The ID of the article.
	 * @param int $tableId The ID of the table.
	 * @param UserIdentity $user The user whose progress is being queried.
	 * @return array An array containing the progress data.
	 */
	public function getProgress( int $articleId, int $tableId, UserIdentity $user ): array {
		$dbr = $this->connectionProvider->getReplicaDatabase();

		$entities = [];

		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'entity_id' ] )
			->from( 'table_progress_tracking' )
			->where( [
				'page_id' => $articleId,
				'table_id' => $tableId,
				'user_id' => $user->getId(),
			] )
			->caller( __METHOD__ )
			->fetchResultSet();

		foreach ( $res as $row ) {
			$entities[] = $row->entity_id;
		}

		return $entities;
	}

	/**
	 * Track the progress of a user on a specific article and table.
	 * This function handles only 1 entity at a time.
	 *
	 * @todo handle duplicate entries, don't return an error, just return a success status
	 *
	 * @param int $articleId The ID of the article.
	 * @param int $tableId The ID of the table.
	 * @param UserIdentity $user The user whose progress is being tracked.
	 * @param mixed $entityId The ID of the entity being tracked.
	 * @return StatusValue A status value indicating success or failure.
	 */
	public function trackProgress( int $articleId, int $tableId, UserIdentity $user, mixed $entityId ): StatusValue {
		$dbw = $this->connectionProvider->getPrimaryDatabase();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'table_progress_tracking' )
			->row( [
				'page_id' => $articleId,
				'table_id' => $tableId,
				'user_id' => $user->getId(),
				'entity_id' => $entityId,
				'tpt_timestamp' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();

		if ( !$dbw->insertId() ) {
			$this->logger->error( 'Failed to track progress for user {user} on article {article} and table {table} with entity {entity}.',
				[
					'user' => $user->getName(),
					'article' => $articleId,
					'table' => $tableId,
					'entity' => $entityId,
				] );
			return StatusValue::newFatal( 'An error occured. Please try again later.' );
		}

		return StatusValue::newGood();
	}

	/**
	 * Delete the progress of a user on a specific article and table.
	 *
	 * @param int $articleId The ID of the article.
	 * @param int $tableId The ID of the table.
	 * @param UserIdentity $user The user whose progress is being deleted.
	 * @param mixed $entityId The ID of the entity being deleted.
	 * @return StatusValue A status value indicating success or failure.
	 */
	public function deleteProgress( int $articleId, int $tableId, UserIdentity $user, mixed $entityId ): StatusValue {
		$dbw = $this->connectionProvider->getPrimaryDatabase();

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'table_progress_tracking' )
			->where( [
				'page_id' => $articleId,
				'table_id' => $tableId,
				'user_id' => $user->getId(),
				'entity_id' => $entityId,
			] )
			->caller( __METHOD__ )
			->execute();

		if ( $dbw->affectedRows() === 0 ) {
			$this->logger->error( 'Failed to delete progress for user {user} on article {article} and table {table} with entity {entity}.',
				[
					'user' => $user->getName(),
					'article' => $articleId,
					'table' => $tableId,
					'entity' => $entityId,
				] );
			return StatusValue::newFatal( 'An error occured. Please try again later.' );
		}

		return StatusValue::newGood();
	}
}
