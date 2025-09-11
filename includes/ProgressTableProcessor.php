<?php

namespace Telepedia\Extensions\TableProgressTracking;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use MediaWiki\Html\Html;
use MediaWiki\MainConfigNames;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;

class ProgressTableProcessor {

	/**
	 * @var string The class for the table cell that contains the checkbox
	 */
	private const CHECKBOX_CELL_CLASS = 'progress-tracker-checkbox-cell';

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
	private ?DOMElement $table = null;

	/**
	 * @var int|null The index of the column that contains the unique identifier for each row.
	 */
	private ?int $uniqueColumnIndex = null;

	/**
	 * @var string|null An error message to be displayed if something goes wrong.
	 */
	private ?string $errorMessage = null;

	/**
	 * Maximum amount of rows that will be parsed by this class before we bail
	 * $wgTableProgressTrackingMaxRows
	 * @var int
	 */
	private int $maxRows = 0;

	/**
	 * Same as above; $wgTableProgressTrackingMaxColumns
	 * @var int
	 */
	private int $maxColumns = 0;

	/**
	 * Maximum size of generated HTML in bytes before we abandon parsing the table
	 * $wgTableProgressTrackingMaxHTMLSize
	 * @var int
	 */
	private int $maxHTMLSize = 0;

	/**
	 * Maximum time we will spend in seconds processing and parsing the table
	 * $wgTableProgressTrackingMaxProcessingTime
	 * @var int
	 */
	private int $maxProcessingTime = 0;

	/**
	 * Time we began processing this wikitext
	 * @var float
	 */
	private float $startTime = 0.0;

	/**
	 * Maximum wikitext size we will try to parse before bailing
	 * @var int
	 */
	private int $maxInputSize = 0;

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

		$config = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'TableProgressTracking' );

		$maxArticleSize = $config->get( MainConfigNames::MaxArticleSize );

		$this->maxColumns = $config->get( 'TableProgressTrackingMaxColumns' );
		$this->maxRows = $config->get( 'TableProgressTrackingMaxRows' );

		// if this wasn't set, then allow us to take 25% of the max article size.
		// in a default MediaWiki install, where $wgMaxArticleSize is unset
		$this->maxHTMLSize = $config->get( 'TableProgressTrackingMaxHTMLSize' ) ?? (int)( $maxArticleSize * 1024 * 0.25 );
		$this->maxProcessingTime = $config->get( 'TableProgressTrackingMaxProcessingTime' );

		// maximum bytes of wikitext we will try and parse. We will allow parsing of either 50KB by default
		// or whatever is configured through $wgTableProcessTrackingMaxInputSize.
		// if the wikitext exceeds this, we bail
		$this->maxInputSize = $config->get( 'TableProgressTrackingMaxInputSize' );


		$size = strlen( $wikitext );
		if ( $size > $this->maxInputSize ) {
			$this->errorMessage = wfMessage( 'table-progress-tracking-max-size-limit', $size, number_format( $this->maxInputSize ) )->text();
			return;
		}

		// Only set the unique column index if it is provided in the arguments
		// if not, we validate later that each row passes its own data-row-id
		// note we must - 1 from the value the user passsed as an argument as 
		// DOMNodeList::item() is zero-based and if the user passed 1 wanting the first column
		// they would get the second
		if ( isset( $this->args['unique-column-index'] ) ) {
			$this->uniqueColumnIndex = intval( $this->args['unique-column-index'] ) - 1;
		}
		
		// check the table-id argument is set, if not, we can't do much herer
		if ( empty( $this->args['table-id'] ) ) {
			$this->errorMessage = 'The table-id argument is required.';
			return;
		}
	}

	/**
	 * Start the timer to measure how long we have been processing this table
	 * @return void
	 */
	private function startProcessingTimer(): void {
		$this->startTime = microtime(true);
	}

	/**
	 * Check if we've exceeded our processing time limit if we have
	 * we will bail
	 * @return bool
	 */
	private function checkTimeout(): bool {
		if ( $this->startTime == 0.0 ) {
			// we haven't started yet
			return false;
		}
		return ( microtime( true ) - $this->startTime ) > $this->maxProcessingTime;
	}

	/**
	 * Do the stuff
	 *
	 * @throws Exception
	 */
	private function loadAndValidateHtml(): void {

		$this->startProcessingTimer();

		if ( $this->checkTimeout() ) {
			// @TODO: wfMessage
			$this->errorMessage = 'Processing timeout exceeded during initialisation.';
			return;
		}
		// first parse our wikitext so we can get the HTML representation if it;
		// we use ->recursiveTagParseFully here as we need the final HTML version of the
		// table so that we can ensure if unique-column-index is used, and the content of the 
		// cell is a link, or any other HTML code, such as bold, then we get the right content
		// in the data-row-id. If we use ->recursiveTagParse(), then we end up with parser strip tags
		// such as <!--LINK'" 0:0--> and there is no easy way to get the link object from the
		// parser that I can find.
		$tableHtml = $this->parser->recursiveTagParseFully( $this->wikitext, $this->frame );

		if ( $this->checkTimeout() ) {
			$this->errorMessage = wfMessage( 'tableprogresstracking-error-parsing-wikitext' )->text();
			return;
		}

		$tableSize = strlen( $tableHtml );

		if ( $tableSize > $this->maxHTMLSize ) {
			$this->errorMessage = wfMessage( "tableprogresstracking-error-html-size", $tableSize, number_format( $this->maxHTMLSize ) );
			return;
		}

		if ( empty( trim( $tableHtml ) ) ) {
			$this->errorMessage = 'Parsing the wikitext resulted in empty HTML.';
			return;
		}

		$this->dom = new DOMDocument();

		// Suppress warnings for potentially malformed HTML from wikitext.
		// there must be a better way to do this?!! Can't find it at present, though?!>!?!
		@$this->dom->loadHTML(
			mb_convert_encoding( $tableHtml, 'HTML-ENTITIES', 'UTF-8' ),
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);

		if ( $this->checkTimeout() ) {
			$this->errorMessage = wfMessage( 'tableprogresstracking-error-parsing-html' )->text();
			return;
		}

		$tableNode = $this->dom->getElementsByTagName( 'table' )->item( 0 );

		if ( !$tableNode ) {
			$this->parser->getOutput()->updateCacheExpiry( 0 );
			$this->errorMessage = 'No table was provided for progress tracking. Please include a table between the <table-progress-tracking> tags.';
			return;
		}

		$this->table = $tableNode;

		// Validate unique-column-index if provided
		if ( $this->uniqueColumnIndex !== null ) {
			$this->validateUniqueColumnIndex();
		}
	}

	/**
	 * Validates that the unique-column-index is within the valid range for the table
	 * @todo there is an error here, if someone passes wikitext to the column which has the unique-column-index, it falls
	 * back to the row index.
	 */
	private function validateUniqueColumnIndex(): void {
		if ( $this->uniqueColumnIndex < 0 ) {
			$this->errorMessage = 'unique-column-index must be 0 or greater.';
			return;
		}

		// Find the maximum number of columns by checking all rows
		$xpath = new DOMXPath( $this->dom );
		$allRows = $xpath->query( './/tr', $this->table );
		$maxColumns = 0;
		$processedRows = 0;

		foreach ( $allRows as $row ) {
			if ( $processedRows >= $this->maxRows || $this->checkTimeout() ) {
				if ( $this->checkTimeout() ) {
					$this->errorMessage = wfMessage( 'tableprogresstracking-error-parsing-html' )->text();
					return;
				}
				break;
			}

			$cellCount = $row->getElementsByTagName( 'td' )->length + $row->getElementsByTagName( 'th' )->length;

			if ( $cellCount > $this->maxColumns ) {
				$this->errorMessage = wfMessage( 'tableprogresstracking-error-max-columns', $this->maxColumns )->text();
				return;
			}

			$maxColumns = max( $maxColumns, $cellCount );
			$processedRows++;
		}

		if ( $this->uniqueColumnIndex >= $maxColumns ) {
			$this->errorMessage = "unique-column-index ({$this->uniqueColumnIndex}) is out of range. Table has {$maxColumns} columns (0-" . ( $maxColumns - 1 ) . ").";
		}
	}

	/**
	 * Validates that all data rows have data-row-id attributes when unique-column-index is not provided
	 */
	private function validateDataRowIds(): bool {
		if ( $this->checkTimeout() ) {
			$this->errorMessage = wfMessage( 'tableprogresstracking-error-parsing-html' )->text();
			return false;
		}
		$xpath = new DOMXPath( $this->dom );
		$dataRows = $xpath->query( './/tr[not(th)]', $this->table );
		$processedRows = 0;

		foreach ( $dataRows as $row ) {
			if ( $processedRows >= $this->maxRows || $this->checkTimeout() ) {
				if ( $this->checkTimeout() ) {
					$this->errorMessage = wfMessage( 'tableprogresstracking-error-parsing-html' )->text();
				} else {
					$this->errorMessage = wfMessage( 'tableprogresstracking-error-max-rows' )->text();
				}
				return false;
			}

			$rowId = $this->extractDataRowId( $row );
			if ( empty( $rowId ) ) {
				$this->errorMessage = 'When unique-column-index is not provided, all data rows must have a data-row-id attribute.';
				return false;
			}
			$processedRows++;
		}

		return true;
	}

	/**
	 * Extracts the data-row-id from a table row, handling multiple occurrences
	 * @param DOMElement $row The row element
	 * @return string|null The data-row-id value, or null if not found
	 */
	private function extractDataRowId( DOMElement $row ): ?string {
		// Check if the row itself has data-row-id
		if ( $row->hasAttribute( 'data-row-id' ) ) {
			return $row->getAttribute( 'data-row-id' );
		}

		// Check cells for data-row-id (using the last one found)
		// this allows us to handle the case where a user passes a data-row-id on more than one column
		$cells = $row->getElementsByTagName( 'td' );
		$lastRowId = null;

		foreach ( $cells as $cell ) {
			if ( $cell->hasAttribute( 'data-row-id' ) ) {
				$lastRowId = $cell->getAttribute( 'data-row-id' );
			}
		}

		return $lastRowId;
	}

	/**
	 * Main processing function; this is where the magic happens.
	 * This could probably be put into one function with the above,
	 * but I think it is better to keep the loading and validation separate from the actual processing of the table
	 * (also modularrrr)
	 *
	 * @return string The final, processed HTML.
	 * @throws Exception
	 */
	public function process(): string {
		// constructor may have returned an error already, so bail before we even start
		if ( $this->hasError() ) {
			return self::renderError( htmlspecialchars( $this->getErrorMessage() ) );
		}

		$this->loadAndValidateHtml();

		if ( $this->hasError() ) {
			return self::renderError( htmlspecialchars( $this->getErrorMessage() ) );
		}

		// If no unique-column-index is provided, validate that all rows have data-row-id
		if ( $this->uniqueColumnIndex === null && !$this->validateDataRowIds() ) {
			return self::renderError( htmlspecialchars( $this->getErrorMessage() ) );
		}

		$this->setTableAttributes();

		if ( !empty( $this->args[ 'header-label' ] ) ) {
			$this->addCustomProgressHeader( $this->args[ 'header-label' ] );
		} else {
			$this->addProgressHeader();
		}

		$this->processDataRows();

		// let's check the erorrs again incase $this->processDataRows exited unsuccessfully
		if ( $this->hasError() ) {
			return self::renderError( htmlspecialchars( $this->getErrorMessage() ) );
		}

		// if we got this far, we can assume the table is valid and ready to be returned
		// lets add a tracking category also so we know which pages are using this extension
		$this->parser->addTrackingCategory( 'tpt-tracking-category' );

		return $this->generateFinalHtml();
	}

	/**
	 * Sets the main data attributes on the <table> element for our JavaScript to use later
	 * @return void [adds to the table element]
	 */
	private function setTableAttributes(): void {
		$tableId = htmlspecialchars( $this->args['table-id'] );
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

		if ( $this->checkTimeout() ) {
			$this->errorMessage = wfMessage( 'tableprogresstracking-error-parsing-html' )->text();
			return;
		}

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
		// disable the checkbox by default, when the JS runs, it will remove the disabled attribute.
		// this is to ensure that no checkbox is selected before the JS initialises (or in the case of an unregistered user,
		// the checkbox will remain disabled)
		$checkBoxInput->setAttribute( 'disabled', 'disabled' );

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
	 * Priority: 1) data-row-id attribute, 2) unique column content, 3) row index fallback
	 * @param DOMElement $row The row element to generate the ID for.
	 * @param int $rowIndex The index of the row in the table.
	 * @return string A unique ID for the row, sanitized to be safe for HTML attributes
	 */
	private function getUniqueRowId( DOMElement $row, int $rowIndex ): string {
		// the most important is the data-row-id, if this is passed, we ignore the unique-column-index
		$dataRowId = $this->extractDataRowId( $row );
		if ( !empty( $dataRowId ) ) {
			return $this->sanitizeRowId( $dataRowId );
		}

		// Wasn't found, use the unique-column-index if it is set
		if ( $this->uniqueColumnIndex !== null ) {
			$tdElements = $row->getElementsByTagName( 'td' );
			if ( $tdElements->length > $this->uniqueColumnIndex ) {
				$uniqueCell = $tdElements->item( $this->uniqueColumnIndex );
				if ( $uniqueCell ) {
					$rowIdContent = trim( $uniqueCell->textContent );
					if ( !empty( $rowIdContent ) ) {
						return $this->sanitizeRowId( $rowIdContent );
					}
				}
			}
		}

		// Fallback to the row index, but maybe in the future we return an erorr here?
		return 'row_' . $rowIndex;
	}

	/**
	 * Sanitizes a row ID to make it safe for HTML attributes
	 * @param string $rowId The raw row ID
	 * @return string The sanitized row ID
	 */
	private function sanitizeRowId( string $rowId ): string {
		return preg_replace( '/[^a-zA-Z0-9_-]/', '_', $rowId );
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
	 * label (therefore, do not use the codex checkbox icon).
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

	/**
	 * Helper function to check if there was an error during processing.
	 * @return bool True if there was an error, false otherwise.
	 */
	public function hasError(): bool {
		return $this->errorMessage !== null;
	}

	/**
	 * Helper function to get the error message if there was an error.
	 * @return string|null The error message, or null if there was no error.
	 */
	public function getErrorMessage(): ?string {
		return $this->errorMessage;
	}

}
