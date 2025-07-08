<?php

namespace Telepedia\Extensions\TableProgressTracking;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Hooks implements ParserFirstCallInitHook, LoadExtensionSchemaUpdatesHook {
	/**
	 * @inheritDoc
	 *
	 */
	public function onParserFirstCallInit( $parser ): void {
		$parser->setHook( "table-progress-tracking", [
			TableGenerator::class,
			"renderProgressTable",
		] );
	}

	/**
	 * @inheritDoc
	 *
	 */
	public function onLoadExtensionSchemaUpdates( $updater ): void {
            $baseDir = dirname( __DIR__, 1 );
			$dbType = $updater->getDB()->getType();

			$updater->addExtensionTable(
    			'table_progress_tracking',
    			"$baseDir/schema/$dbType/tables-generated.sql",
			);
	}
}
