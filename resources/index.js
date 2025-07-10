var ProgressTracker = {
    options: {
        storageKey: 'mediawiki.table-progress-tracking',
        selectors: {
            checkbox: 'input[type="checkbox"][data-row-id]',
            table: 'table[data-progress-table-id]'
        },
        attributes: {
            tableId: 'data-progress-table-id',
            rowId: 'data-row-id'
        },
        classes: {
            saving: 'is-saving',
            error: 'has-error'
        }
    },

    progressData: new Map(),
    pageId: mw.config.get( 'wgArticleId' ),
    intersectionObserver: null,
    isInitialised: false,

    init: function () {
        if ( this.isInitialised ) {
            return;
        }

        this.setupIntersectionObserver();
        this.setupEventListeners();

        this.isInitialised = true;
    },

    /**
    * Setup the intersection observer so that once the table is within 100px of the viewport, we load the 
    * relevant data either from local storage or from the API. This helps us avoid making a HTTP request before the table
    * scrolls into view, on the off chance that the table never comes into view and we potentially save a backend trip.
    */
    setupIntersectionObserver: function () {
        // if we don't have IntersectionObserver (on older browsers), then return, nothing we can do
        // at this point. Eventually will add a fallback
        if ( !window.IntersectionObserver ) {
            console.error( 'TableProgressTracking: IntersectionObserver is not supported. Progress tracking is unavailable.' );
            return;
        }

        this.intersectionObserver = new IntersectionObserver( function ( entries ) {
            entries.forEach( function ( entry ) {
                if ( entry.isIntersecting ) {
                    let table = entry.target;
                    let tableId = table.getAttribute( this.options.attributes.tableId );

                    if ( tableId ) {
                        this.loadTableProgress( tableId ).then( function () {
                            this.syncTableCheckboxes( table, tableId );
                            this.attachCheckboxListeners( table );
                        }.bind( this ) );
                    }

                    this.intersectionObserver.unobserve( table );
                }
            }.bind( this ) );
        }.bind( this ), {
            root: null,
            rootMargin: '100px',
            threshold: 0
        } );
    },

    loadTableProgress: function( tableId ) {
        if ( this.progressData.has( tableId ) ) {
            return Promise.resolve( this.progressData.get( tableId ) );
        }

        let progress = [];
        let stored;

        // first let us try local storage, if the user has visited this page previously then their data
        // will be stored in local storage to avoid a backend trip
        try {
            stored = localStorage.getItem( `${this.options.storageKey}-${this.pageId}-${tableId}` );
            if ( stored ) {
                progress = JSON.parse( stored );
            }
        } catch ( e ) {
            console.error( "Could not read from LocalStorage.", e );
        }

        // we didn't have anything in the local storage, so lets make a backend request to get the data
        const api = new mw.Rest();

        return api.get( `/progress-tracking/${this.pageId}/${tableId}` ).then( function ( response ) {
            if ( response ) {
                progress = response;
            }

            this.progressData.set( tableId, progress );
            
            try {
                localStorage.setItem( `${this.options.storageKey}-${this.pageId}-${tableId}`, JSON.stringify( progress ) );
            } catch ( e ) {
                console.error( "Could not write to LocalStorage.", e );
            }
            
            return progress;
        }.bind( this ) ).catch( function ( error ) {
            
            // Still set the progress data (even if empty) and return it
            this.progressData.set( tableId, progress );
            return progress;
        }.bind( this ) );
    },

    setupEventListeners: function() {
        const tables = document.querySelectorAll( this.options.selectors.table );
        
        tables.forEach( function ( table ) {;
            if ( this.intersectionObserver ) {
                this.intersectionObserver.observe( table );
            } 
        }.bind( this ) );

        // @TODO: if intersectiton observer is not available, process the table immediately
        // need to check the compatibility of the IntersectionObserver API against MediaWiki's supported browsers
    },

    syncTableCheckboxes: function ( table, tableId ) { 
        let progress = this.progressData.get( tableId ) || [];

        table.querySelectorAll( this.options.selectors.checkbox ).forEach( function ( checkbox ) {
            let rowId = checkbox.getAttribute( this.options.attributes.rowId );
            if ( rowId ) {
                let isChecked = progress.includes( rowId );
                checkbox.checked = isChecked;
            }
        }.bind( this ) ); 
    }
};


(function () {
    mw.hook('wikipage.content').add( ProgressTracker.init.bind( ProgressTracker ) );
})();