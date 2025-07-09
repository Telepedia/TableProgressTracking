<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use Telepedia\Extensions\TableProgressTracking\ProgressService;

return [

	'TableProgressTracking.ProgressService' => static function (
		MediaWikiServices $services
	): ProgressService {
		return new ProgressService(
			new ServiceOptions( ProgressService::CONSTRUCTOR_OPTIONS, $services->getMainConfig() ),
			LoggerFactory::getInstance( 'ProgressService' ),
			$services->getConnectionProvider()
		);
	}

];
