<?php

namespace Telepedia\Extensions\TableProgressTracking\Rest;

use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Telepedia\Extensions\TableProgressTracking\ProgressService;
use Wikimedia\ParamValidator\ParamValidator;

class DeleteProgressHandler extends SimpleHandler {

	public function __construct(
		private readonly ProgressService $progressService
	) {
	}

	/**
	 * Delete a users progress on a specific article and table
	 * The entity ID is expected to be passed in the request body as JSON (only one at a time).
	 * @param int $articleId The ID of the article.
	 * @param int $tableId The ID of the table.
	 * @return Response
	 */
	public function run( int $articleId, int $tableId ): Response {
		$user = $this->getAuthority()->getUser();

		if ( !$user->isRegistered() ) {
			return $this->getResponseFactory()->createHttpError(
				403,
				[
					'error' => 'You must be logged in to track progress.',
				]
			);
		}

		$body = $this->getValidatedBody();

		if ( !isset( $body['entity_id'] ) ) {
			return $this->getResponseFactory()->createHttpError(
				400,
				[
					'error' => 'Invalid or missing entity_id.',
				]
			);
		}

		// the database uses VARCHAR to allow for numeric and non-numeric entity ids, so cast the integer to a string
		$entityId = (string)$body['entity_id'];

		$res = $this->progressService->deleteProgress(
            $articleId,
            $tableId,
            $user,
            $entityId
        );

		if ( !$res->isOK() ) {
			return $this->getResponseFactory()->createHttpError(
				500,
				[
					'error' => 'Failed to delete progress.',
				]
			);
		}

		return $this->getResponseFactory()->createNoContent();
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings() {
		return [
			'articleId' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'tableId' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}

	public function getBodyParamSettings(): array {
		return [
			'entity_id' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			]
		];
	}
}
