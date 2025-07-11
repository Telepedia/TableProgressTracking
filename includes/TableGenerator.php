<?php

namespace Telepedia\Extensions\TableProgressTracking;

use Exception;
use MediaWiki\Html\Html;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;

class TableGenerator {

	/**
	 * Entrypoint to render the progress tracking table.
	 * This function is called from the onParserFirstCallInit (use the <table-progress-tracking> tag).
	 *
	 * @param string|null $input contents between the <table-progress-tracking> tag (or null if empty)
	 * @param array $args The attributes of the tag
	 * @param Parser $parser The parser object.
	 * @param PPFrame $frame The frame object.
	 * @return string The HTML to be outputted.
	 * @link https://www.mediawiki.org/wiki/Manual:Tag_extensions
	 */
	public static function renderProgressTable( ?string $input, array $args, Parser $parser, PPFrame $frame ): string {
		// For some reason $parser->getOutput()->setEnabledOOUI( true ) doesn't work?!
		OutputPage::setupOOUI();

		// Switch this to using codex icons eventually to avoid the FOUC on the check icon
		$parser->getOutput()->addModuleStyles( [ 'ext.tableProgressTracking.styles' ] );
		$parser->getOutput()->addModules( [ 'ext.tableProgressTracking.scripts' ] );

		try {
			if ( empty( $input ) ) {
				return self::renderError( 'No content found inside the <table-progress-tracking> tag.' );
			}

			$processor = new ProgressTableProcessor( $input, $args, $parser, $frame );
			return $processor->process();

		} catch ( Exception $e ) {
			// fallback to MediaWiki's error rendering if an exception occurs
			return self::renderError( $e->getMessage() );
		}
	}

	/**
	 * Renders a MediaWiki html error box
	 *
	 * @param string $message The error message to display.
	 * @return string
	 */
	private static function renderError( string $message ): string {
		$escapedMessage = htmlspecialchars( $message );
		return Html::errorBox( $escapedMessage );
	}
}
