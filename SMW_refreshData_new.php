<?php
/**
 * Recreates all the semantic data in the database, by cycling through all
 * the pages that might have semantic data, and calling functions that
 * re-save semantic data for each one.
 *
 * Note: if SMW is not installed in its standard path under ./extensions
 *       then the MW_INSTALL_PATH environment variable must be set.
 *       See README in the maintenance directory.
 *
 * Usage:
 * php SMW_refreshData.php [options...]
 *
 * -d <delay>   Wait for this many milliseconds after processing an article, useful for limiting server load.
 * -s <startid> Start refreshing at given article ID, useful for partial refreshing
 * -e <endid>   Stop refreshing at given article ID, useful for partial refreshing
 * -n <numids>  Stop refreshing after processing a given number of IDs, useful for partial refreshing
 * --startidfile <startidfile> Read <startid> from a file instead of the arguments and write the next id
 *              to the file when finished. Useful for continual partial refreshing from cron.
 * -b <backend> Execute the operation for the storage backend of the given name
 *              (default is to use the current backend)
 * -v           Be verbose about the progress.
 * -c           Will refresh only category pages (and other explicitly named namespaces)
 * -p           Will refresh only property pages (and other explicitly named namespaces)
 * -t           Will refresh only type pages (and other explicitly named namespaces)
 * --page=<pagelist> will refresh only the pages of the given names, with | used as a separator.
 *              Example: --page="Page 1|Page 2" refreshes Page 1 and Page 2
 *              Options -s, -e, -n, --startidfile, -c, -p, -t are ignored if --page is given.
 * -f           Fully delete all content instead of just refreshing relevant entries. This will also
 *              rebuild the whole storage structure. May leave the wiki temporarily incomplete.
 * --server=<server> The protocol and server name to as base URLs, e.g.
 *              http://en.wikipedia.org. This is sometimes necessary because
 *              server name detection may fail in command line scripts.
 *
 * @author Yaron Koren
 * @author Markus Krötzsch
 *
 * this file has been rewritten in new style by Sakthi Velmani
 * @file
 * @ingroup SMWMaintenance
 */


require_once ( getenv( 'MW_INSTALL_PATH' ) !== false
	? getenv( 'MW_INSTALL_PATH' ) . "/maintenance/Maintenance.php"
	: dirname( __FILE__ ) . '/../../../maintenance/Maintenance.php' );

class SMWrefreshData extends Maintenance {
	
	public function __construct()  {
		parent::__construct();
		$this->mDescription = 'Refreshes all data'; 
		$this->addArg( 'backend', 'Executes the operation of the given name.', false );
		$this->addArg( 'startid', '', false );
		$this->addArg( 'delay', '', false );
		$this->addArg( 'endid', '', false );
		$this->addArg( 'numids', '', false );
		$this->addArg( 'startidfile', '', false );
		$this->addArg( 'server' , '', false );
		$this->addArg( 'page' , '' , false );
	}

	public function execute() {
		
		global $smwgEnableUpdateJobs, $wgServer, $wgTitle;
		$wgTitle = Title::newFromText( 'SMW_refreshData.php' );
		$smwgEnableUpdateJobs = false; // do not fork additional update jobs while running this script


		$wgserver = $this->getArg( 'server', false );


		$delay = $this->getArg( 'delete' );
		if ( $delay == true ) {
			$delay = intval( $delay * 100000 );
		}


		if ( $this->hasOption( 'page' ) ) {
			$pages = explode( '|', $this->getArg( 'page' ) );
		} else { 
			$pages = false;
		}



		$writeToStartidfile = false;


		if ( $this->hasOption( 'startid' ) ) {
			$start = max( 1, intval( $this->getArg( 'startid' , false ) ) );
		} elseif ( $this->hasOption( 'startidfile' ) ) {
			if ( !is_writable( file_exists( $this->getArg( 'startidfile' ) ) ? ( $this->getArg( 'startidfile' ) ) : dirname( $this->getArg( 'startidfile' ) ) ) ) {
			die ("cannot use a startidfile that we can't write to.\n");
			}
		$writeToStartidfile = true;
			if ( is_readable( $this->getArg( 'startidfile' ) ) ) {
				$start = max( 1, intval( file_get_contents( $this->getArg( 'startidfile' ) ) ) );
			} else {
				$start = 1;
			}
		} else {
			$start = 1;
		}


		if ( $this->hasOption( 'e' ) ) {
			$end = intval( $this->getArg( 'e' ) );
		} elseif ( $this->hasOption( 'n' ) ) {
			$end = $start + intval( $this->getArg( 'n' ) );
		} else {
			$end = false;
		}



		if  ( $this->hasOption( 'b' , false ) ) {
			global $smwgDefaultStore;
			$smwgDefaultStore = $this->getArg( 'b' );
			print "\nSelected storage $smwgDefaultStore for update!\n\n";
		}


		$verbose = $this->hasOption( 'v' , false );

		$filterarray = array();
		if ( $this->hasOption( 'c' ) ) {
			$filterarray[] = NS_CATEGORY;
		}
		if ( $this->hasOption( 'p' ) ) {
			$filterarray[] = SMW_NS_PROPERTY;
		}
		if ( $this->hasOption( 't' ) ) {
			$filterarray[] = SMW_NS_TYPE;
		}
			$filter = count( $filterarray ) > 0 ? $filterarray : false;

		global $smwgIP;
		if ( !isset( $smwgIP ) ) {
			$smwgIP = dirname(__FILE__) . '/../';
		}

		require_once( $smwgIP . 'includes/SMW_GlobalFunctions.php' );

		if ( $this->hasOption( 'f' ) ) {
			print "\n  Deleting all stored data completely and rebuilding it again later!\n  Semantic data in the wiki might be incomplete for some time while this operation runs.\n\n  NOTE: It is usually necessary to run this script ONE MORE TIME after this operation,\n  since some properties' types are not stored yet in the first run.\n  The first run can normally use the parameter -p to refresh only properties.\n\n";
			if ( ( $this->hasOption( 's' ) ) || ( $this->hasOption( 'e' ) ) ) {
				print "  WARNING: -s or -e are used, so some pages will not be refreshed at all!\n    Data for those pages will only be available again when they have been\n    refreshed as well!\n\n";
			}

			print 'Abort with control-c in the next five seconds ...  ';
			wfCountDown( 6 );

			smwfGetStore()->drop( $verbose );
			wfRunHooks( 'smwDropTables' );
			print "\n";
			smwfGetStore()->setup( $verbose );
			wfRunHooks( 'smwInitializeTables' );
			while ( ob_get_level() > 0 ) { // be sure to have some buffer, otherwise some PHPs complain
				ob_end_flush();
			}
			echo "\nAll storage structures have been deleted and recreated.\n\n";
		}

		$linkCache = LinkCache::singleton();
		$num_files = 0;
		if ( $pages == false ) {
			print "Refreshing all semantic data in the database!\n---\n" .
			" Some versions of PHP suffer from memory leaks in long-running scripts.\n" .
			" If your machine gets very slow after many pages (typically more than\n" .
			" 1000) were refreshed, please abort with CTRL-C and resume this script\n" .
			" at the last processed page id using the parameter -s (use -v to display\n" .
			" page ids during refresh). Continue this until all pages were refreshed.\n---\n";
			print "Processing all IDs from $start to " . ( $end ? "$end" : 'last ID' ) . " ...\n";

			$id = $start;
			while ( ( ( !$end ) || ( $id <= $end ) ) && ( $id > 0 ) ) {
				if ( $verbose ) {
					print "($num_files) Processing ID " . $id . " ...\n";
				}				
				smwfGetStore()->refreshData( $id, 1, $filter, false );
				if ( ( $delay !== false ) && ( ( $num_files + 1 ) % 100 === 0 ) ) {
					usleep( $delay );
				}
				$num_files++;
				$linkCache->clear(); // avoid memory leaks
			}
			if ( $writeToStartidfile ) {
				file_put_contents( $this->getArg( 'startidfile' ) , "$id" );
			}
			print "$num_files IDs refreshed.\n";
		} else {
			print "Refreshing specified pages!\n\n";
	
			foreach ( $pages as $page ) {
				if ( $verbose ) {
					print "($num_files) Processing page " . $page . " ...\n";
				}
		
				$title = Title::newFromText( $page );
		
				if ( !is_null( $title ) ) {
					$updatejob = new SMWUpdateJob( $title );
					$updatejob->run();
				}
		
				$num_files++;
			}
	
			print "$num_files pages refreshed.\n";
		}
	} 
}

$maintClass = 'SMWrefreshData';
require_once( RUN_MAINTENANCE_IF_MAIN );
