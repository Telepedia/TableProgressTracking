<?php

namespace Telepedia\Extensions\TableProgressTracking\Rest;

use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Rest\TokenAwareHandlerTrait;
use MediaWiki\Rest\Validator\Validator;
use Telepedia\Extensions\TableProgressTracking\ProgressService;
use Wikimedia\ParamValidator\ParamValidator;

class TrackProgressHandler extends SimpleHandler {

	use TokenAwareHandlerTrait;
	
	public function __construct(
		private readonly ProgressService $progressService
	) {
	}

	/**
	 * Track a users progress on a specific article and table
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

		$res = $this->progressService->trackProgress(
			$articleId,
			$tableId,
			$user,
			$entityId
		);

		if ( !$res->isOK() ) {
			return $this->getResponseFactory()->createHttpError(
				500,
				[
					'error' => 'Failed to track progress.',
				]
			);
		}

		$response = $this->getResponseFactory()->create();
		$response->setStatus( 201 );

		return $response;
	}

	/**
	 * @inheritDoc
	 */
	public function needsWriteAccess(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	public function validate( Validator $restValidator ): void {
		parent::validate( $restValidator );
		$this->validateToken( false );
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
		]  + $this->getTokenParamDefinition();
	}
}
