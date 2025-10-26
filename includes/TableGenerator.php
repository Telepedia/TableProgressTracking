<?php

namespace Telepedia\Extensions\TableProgressTracking;

use Exception;
use MediaWiki\Content\TextContent;
use MediaWiki\Html\Html;
use MediaWiki\Output\OutputPage;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use MediaWiki\Revision\RenderedRevision;

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

	/**
	 * Check for duplicate tables with the same table-id on a page
	 * This doesn't really make sense in this class, but making a new class solely for that seems overkill
	 * @return bool true if duplicates are found (and the edit should be prevented), false otherwise
	 */
	public static function hasDuplicateTables( RenderedRevision $revision ): bool {
		$content = $revision->getRevision()->getContent( 'main' );

		// no content, no tables, no duplicates :P
		// if not TextContent then no tables either
		if ( $content->isEmpty() || !$content instanceof TextContent ) {
			return false;
		}

		$text = $content->getText();

		// match all <table-progress-tracking> tags with a table-id attribute, in any order
		preg_match_all(
			'/<table-progress-tracking[^>]*\btable-id\s*=\s*["\']?([^"\'>\s]+)["\']?[^>]*>/i',
			$text,
			$matches
		);

		if ( empty( $matches[1] ) ) {
			return false;
		}

		$counts = array_count_values( $matches[1] );

		// Return true if any table-id appears more than once
		foreach ( $counts as $id => $count ) {
			if ( $count > 1 ) {
				return true;
			}
		}

		return false;
	}
}
