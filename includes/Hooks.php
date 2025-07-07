<?php

namespace Telepedia\Extensions\TableProgressTracking;

use MediaWiki\Hook\ParserFirstCallInitHook;

class Hooks implements ParserFirstCallInitHook {
 
    /**
     * @inheritDoc
     * 
     */
    public function onParserFirstCallInit( $parser ) {
        $parser->setHook( 'table-progress-tracking', [ TableGenerator::class, 'renderProgressTable' ] );
    }
}