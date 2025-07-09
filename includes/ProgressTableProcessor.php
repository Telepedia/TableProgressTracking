<?php

namespace Telepedia\Extensions\TableProgressTracking;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use MediaWiki\Html\Html;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;

class ProgressTableProcessor {

	/**
	 * @var string The class for the table cell that contains the checkbox
	 */
	private const CHECKBOX_CELL_CLASS = 'progress-tracker-checkbox-cell';

	/**
	 * @var string The class for the checkbox input element
	 */
	private const CHECKBOX_CLASS = 'progress-tracker-checkbox';

	/**
	 * @var string The class for the container that wraps the entire progress tracking table
	 */
	private const CONTAINER_CLASS = 'progress-tracker-container';

	/**
	 * @var string The wikitext between the opening and closing tags of the <table-progress-tracking> tag.
	 */
	private string $wikitext;

	/**
	 * @var array The attributes of the <table-progress-tracking> tag.
	 */
	private array $args;

	/**
	 * @var Parser The instance of the MediaWiki parser which is currently processing this page
	 */
	private Parser $parser;

	/**
	 * @var PPFrame The current frame we are parsing
	 */
	private PPFrame $frame;

	/**
	 * @var DOMDocument
	 */
	private DOMDocument $dom;

	/**
	 * @var DOMElement
	 */
	private DOMElement $table;

	/**
	 * @var int The index of the column that contains the unique identifier for each row.
	 */
	private int $uniqueColumnIndex;

	/**
	 * Constructor
	 *
	 * @throws Exception If the input is invalid or a table cannot be found.
	 */
	public function __construct( string $wikitext, array $args, Parser $parser, PPFrame $frame ) {
		$this->wikitext = $wikitext;
		$this->args = $args;
		$this->parser = $parser;
		$this->frame = $frame;
		$this->uniqueColumnIndex = intval( $this->args['unique-column-index'] ?? 0 ); // @TDOD, error instead of 0?

		$this->loadAndValidateHtml();
	}

	/**
	 * Do the stuff
	 *
	 * @throws Exception
	 */
	private function loadAndValidateHtml(): void {
		// first parse our wikitext so we can get the HTML representation if it;
		$tableHtml = $this->parser->recursiveTagParse( $this->wikitext, $this->frame );

		if ( empty( trim( $tableHtml ) ) ) {
			self::renderError( 'Parsing the wikitext resulted in empty HTML.' );
		}

		$this->dom = new DOMDocument();

		// Suppress warnings for potentially malformed HTML from wikitext.
		// there must be a better way to do this?!! Can't find it at present, though?!>!?!
		@$this->dom->loadHTML(
			mb_convert_encoding( $tableHtml, 'HTML-ENTITIES', 'UTF-8' ),
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		$tableNode = $this->dom->getElementsByTagName( 'table' )->item( 0 );

		if ( !$tableNode ) {
			// disable caching for this page until the error is resolved? Does an exception automatically
			// disable caching? Also, switch to self::renderError()
			$this->parser->getOutput()->updateCacheExpiry( 0 );
			throw new Exception( 'No table was provided for progress tracking. Please include a table between the <table-progress-tracking> tags.' );
		}

		$this->table = $tableNode;
	}

	/**
	 * Main processing function; this is where the magic happens.
	 * This could probably be put into one function with the above,
	 * but I think it is better to keep the loading and validation separate from the actual processing of the table
	 * (also modularrrr)
	 *
	 * @return string The final, processed HTML.
	 */
	public function process(): string {
		$this->setTableAttributes();

		if ( !empty( $this->args[ 'header-label' ] ) ) {
			$this->addCustomProgressHeader( $this->args[ 'header-label' ] );
		} else {
			$this->addProgressHeader();
		}

		$this->processDataRows();

		return $this->generateFinalHtml();
	}

	/**
	 * Sets the main data attributes on the <table> element for our JavaScript to use later
	 */
	private function setTableAttributes(): void {
		// @todo: throw an error if the table-id is not set
		$tableId = htmlspecialchars( $this->args['table-id'] ?? '0' );
		$this->table->setAttribute( 'data-progress-table-id', $tableId );
		$this->table->setAttribute( 'class', $this->table->getAttribute( 'class' ) . ' progress-tracking-table' );
	}

	/**
	 * Finds the first header row, and adds the user supplied column title, or a checkbox if not supplied.
	 * @todo actually use their content if provided, for now just adds the icon anyway
	 */
	private function addProgressHeader(): void {
		$xpath = new DOMXPath( $this->dom );
		$headerRow = $xpath->query( './/tr[th]', $this->table )->item( 0 );

		if ( $headerRow ) {

			$progressHeader = $this->dom->createElement( 'th' );

			$headerDiv = $this->dom->createElement( 'span' );
			$headerDiv->setAttribute( 'class', 'ext-tableProgressTracking-icon-check' );

			$progressHeader->appendChild( $headerDiv );

			$headerRow->insertBefore( $progressHeader, $headerRow->firstChild );
		}
	}

	/**
	 * Iterates over all data rows (tr without th) and adds the checkbox cell to each.
	 */
	private function processDataRows(): void {
		$xpath = new DOMXPath( $this->dom );
		// this is fucked, but this should be better than just trying to get the tr element with ->getElementByTagName('tr') as that will return all tr elements, including the header ones
		$dataRows = $xpath->query( './/tr[not(th)]', $this->table );
		$rowIndex = 0;

		foreach ( $dataRows as $r ) {
			$this->addCheckboxCellToRow( $r, $rowIndex++ );
		}
	}

	/**
	 * Creates and adds a progress tracking checkbox cell to a single data row.
	 * @param DOMElement $row the row we are currently working on
	 * @param int $rowIndex the index we are applying to the row
	 * @return void
	 */
	private function addCheckboxCellToRow( DOMElement $row, int $rowIndex ): void {
		$rowId = $this->getUniqueRowId( $row, $rowIndex );
		$row->setAttribute( 'data-row-id', $rowId );

		// outer wrapper
		$checkboxDiv = $this->dom->createElement( 'div' );
		$checkboxDiv->setAttribute( 'class', 'cdx-checkbox' );

		// wrapper
		$checkBoxWrapper = $this->dom->createElement( 'div' );
		$checkBoxWrapper->setAttribute( 'class', 'cdx-checkbox__wrapper' );

		// start input
		$checkBoxInput = $this->dom->createElement( 'input' );
		$checkBoxInput->setAttribute( 'type', 'checkbox' );
		$checkBoxInput->setAttribute( 'class', 'cdx-checkbox__input' );
		$checkBoxInput->setAttribute( 'data-row-id', $rowId );
		$checkBoxInput->setAttribute( 'id', $rowId );

		// empty span for the icon as per:
		// https://doc.wikimedia.org/codex/main/components/demos/checkbox.html#css-only-version
		$checkBoxSpan = $this->dom->createElement( 'span' );
		$checkBoxSpan->setAttribute( 'class', 'cdx-checkbox__icon' );

		// create the label container
		$checkBoxLabelContainer = $this->dom->createElement( 'div' );
		$checkBoxLabelContainer->setAttribute( 'class', 'cdx-checkbox__label cdx-label' );

		// start label
		$checkBoxLabel = $this->dom->createElement( 'label' );
		$checkBoxLabel->setAttribute( 'for', $rowId );
		$checkBoxLabel->setAttribute( 'class', 'cdx-label__label' );

		// empty label as we don't need any text
		$checkBoxLabelText = $this->dom->createElement( 'span', ' ' );
		$checkBoxLabelText->setAttribute( 'class', 'cdx-label__label__text' );

		// put everything together
		$checkBoxLabel->appendChild( $checkBoxLabelText );
		$checkBoxLabelContainer->appendChild( $checkBoxLabel );

		$checkBoxWrapper->appendChild( $checkBoxInput );
		$checkBoxWrapper->appendChild( $checkBoxSpan );
		$checkBoxWrapper->appendChild( $checkBoxLabelContainer );

		$checkboxDiv->appendChild( $checkBoxWrapper );

		$cell = $this->dom->createElement( 'td' );
		$cell->setAttribute( 'class', self::CHECKBOX_CELL_CLASS );
		$cell->appendChild( $checkboxDiv );

		$row->insertBefore( $cell, $row->firstChild );
	}

	/**
	 * Generates a unique and safe ID for a given row.
	 * It first tries to use the content of the specified unique column,
	 * & sanitizes it. If that fails, it falls back to a row index.
	 * @todo not sure if this is the right approach at the minute, we should potentially throw an error or smth?
	 * @param DOMElement $row The row element to generate the ID for.
	 * @param int $rowIndex The index of the row in the table.
	 * @return string A unique ID for the row, sanitized to be safe for HTML attributes
	 */
	private function getUniqueRowId( DOMElement $row, int $rowIndex ): string {
		$tdElements = $row->getElementsByTagName( 'td' );
		$rowIdContent = null;

		if ( $tdElements->length > $this->uniqueColumnIndex ) {
			$uniqueCell = $tdElements->item( $this->uniqueColumnIndex );
			if ( $uniqueCell ) {
				$rowIdContent = trim( $uniqueCell->textContent );
			}
		}

		if ( !empty( $rowIdContent ) ) {
			// Sanitize to make it a safe value for HTML attributes.
			return preg_replace( '/[^a-zA-Z0-9_-]/', '_', $rowIdContent );
		}

		// Fallback if the specified column is empty or doesn't exist.
		return 'row_' . $rowIndex;
	}

	 /**
	  * Helper function to generate the final HTML output.
	  * (here in a separate function incase we want to wrap the table in a container or something later)
	  */
	private function generateFinalHtml(): string {
		return $this->dom->saveHTML( $this->table );
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
	 * Helper function to add the header for the progress tracking column, if the user provided their own
	 * label (therefore, do not use the OOUI checkbox icon).
	 * @param string $headerLabel The label to use for the progress tracking header.
	 * @return void [adds to the table]
	 */
	private function addCustomProgressHeader( string $headerLabel ): void {
		$xpath = new DOMXPath( $this->dom );
		$headerRow = $xpath->query( './/tr[th]', $this->table )->item( 0 );

		if ( $headerRow ) {
			$progressHeader = $this->dom->createElement( 'th' );
			$progressHeader->textContent = htmlspecialchars( $headerLabel );

			$headerRow->insertBefore( $progressHeader, $headerRow->firstChild );
		}
	}
}
