<?php

namespace Telepedia\Extensions\TableProgressTracking\Rest;

use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use Telepedia\Extensions\TableProgressTracking\ProgressService;
use Wikimedia\ParamValidator\ParamValidator;

class GetProgressHandler extends SimpleHandler {

	public function __construct(
		private readonly ProgressService $progressService
	) {
	}

	/**
	 * Get a users progress for this a specific table on a specific article
	 * The entity ID is expected to be passed in the request body as JSON (only one at a time).
	 * @param int $articleId The ID of the article.
	 * @param int $tableId The ID of the table.
	 * @return Response (array containing either the entities, or empty for none)
	 */
	public function run( int $articleId, int $tableId ): Response {
		$user = $this->getAuthority()->getUser();

		if ( !$user->isRegistered() ) {
			return $this->getResponseFactory()->createHttpError(
				403,
				[
					'error' => 'You must be logged in to retrieve tracked progress.',
				]
			);
		}


        $res = $this->progressService->getProgress($articleId, $tableId, $user );

		return $this->getResponseFactory()->createJson( $res );
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
}
