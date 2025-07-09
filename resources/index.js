var ProgressTracker = {
    options: {
        storageKey: 'mediawiki.table-progress-tracking',
        selectors: {
            checkbox: 'input[type="checkbox"][data-row-id][data-table-id]',
            table: 'table[data-table-id]'
        },
        attributes: {
            tableId: 'data-table-id',
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

        this.setupEventListeners();
        this.setupIntersectionObserver();
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

        try {
            let response = api.get( `/progress-tracking/${this.pageId}/${tableId}`);
            if ( response ) {
                this.progressData.set( tableId, progress );
            }

            localStorage.setItem(`${this.options.storageKey}-${this.pageId}-${tableId}`, JSON.stringify(progress));
        } catch ( error ) {
            console.log( "TableProgressTracking: failed to fetch data: ", error );
        }

        this.progressData.set( tableId, progress );
        return progress;
    }
}
