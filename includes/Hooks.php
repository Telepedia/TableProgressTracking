<?php

namespace Telepedia\Extensions\TableProgressTracking;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;
use MediaWiki\Storage\Hook\MultiContentSaveHook;

class Hooks implements ParserFirstCallInitHook, LoadExtensionSchemaUpdatesHook, MultiContentSaveHook {
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

	/**
	 * @inheritDoc
	 */
	public function onMultiContentSave( $renderedRevision, $user, $summary, $flags, $status ) {
		if ( TableGenerator::hasDuplicateTables( $renderedRevision ) ) {
			$status->fatal( 'tableprogresstracking-duplicate-tables' );
			return false;
		}

		return true;
	}
}
