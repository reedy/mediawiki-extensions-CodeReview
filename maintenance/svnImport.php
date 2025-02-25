<?php

use MediaWiki\Extension\CodeReview\Backend\CodeRepository;
use MediaWiki\Extension\CodeReview\Backend\CodeRevision;
use MediaWiki\Extension\CodeReview\Backend\SubversionAdaptor;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class SvnImport extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Import revisions to Code Review from a Subversion repo' );
		$this->addOption( 'precache', 'Pre-cache diffs for last N revisions. ' .
			'May be a positive integer, 0 (for none) or \'all\'.  Default is 0', false, true );
		$this->addArg( 'repo', 'The name of the repo. Use \'all\' to import from all defined repos' );
		$this->addArg( 'start', 'The revision to begin the import from. If not specified then ' .
			'it starts from the last repo imported to the wiki. Ignored if ' .
			"'all' is specified for <repo>", false );

		$this->requireExtension( 'CodeReview' );
	}

	public function execute() {
		$cacheSize = 0;
		if ( $this->hasOption( 'precache' ) ) {
			$cacheSize = $this->getOption( 'precache' );
			if ( strtolower( $cacheSize ) !== 'all' ) {
				if ( preg_match( '/^\d+$/', $cacheSize ) ) {
					$cacheSize = intval( $cacheSize );
				} else {
					$this->fatalError( "Invalid argument for --precache (must be a positive integer," .
						" 0 or 'all')" );
				}
			}
		}

		$repo = $this->getArg( 0 );

		if ( $repo == 'all' ) {
			$repoList = CodeRepository::getRepoList();
			/**
			 * @var $repoInfo CodeRepository
			 */
			foreach ( $repoList as $repoInfo ) {
				$this->importRepo( $repoInfo->getName(), null, $cacheSize );
			}
		} else {
			$startRev = null;
			if ( $this->hasArg( 1 ) ) {
				$startRev = $this->getArg( 1 );
			}
			$this->importRepo( $repo, $startRev, $cacheSize );
		}
	}

	/**
	 * Import a repository in the local database.
	 * @param string $repoName Local name of repository
	 * @param int|null $start Revision to begin the import from
	 *   (Default: null, means last stored revision);
	 * @param int $cacheSize
	 * @return void
	 */
	private function importRepo( $repoName, $start = null, $cacheSize = 0 ) {
		global $wgCodeReviewImportBatchSize;

		static $adaptorReported = false;

		$repo = CodeRepository::newFromName( $repoName );

		if ( !$repo ) {
			$this->error( "Invalid repo $repoName" );
			return;
		}

		$svn = SubversionAdaptor::newFromRepo( $repo->getPath() );
		if ( !$adaptorReported ) {
			$this->output( 'Using ' . get_class( $svn ) . " adaptor\n" );
			$adaptorReported = true;
		}

		$this->output( "IMPORT FROM REPO: $repoName\n" );
		$lastStoredRev = $repo->getLastStoredRev();
		$this->output( "Last stored revision: $lastStoredRev\n" );

		$chunkSize = $wgCodeReviewImportBatchSize;

		$startTime = microtime( true );
		$revCount = 0;
		$start = ( $start !== null ) ? intval( $start ) : $lastStoredRev + 1;

		/*
		 * FIXME: when importing only a part of a repository, the given path
		 * might not have been created with revision 1. For example, the
		 * mediawiki '/trunk/phase3' got created with r1284.
		 */
		if ( $start > ( $lastStoredRev + 1 ) ) {
			$this->error( "Invalid starting point. r{$start} is beyond last stored revision: r" .
				( $lastStoredRev + 1 ) );
			return;
		}

		$this->output( "Syncing from r$start to HEAD...\n" );

		if ( !$svn->canConnect() ) {
			$this->error( "Unable to connect to repository." );
			return;
		}

		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

		while ( true ) {
			$log = $svn->getLog( '', $start, $start + $chunkSize - 1 );
			if ( empty( $log ) ) {
				# Repo seems to give a blank when max rev is invalid, which
				# stops new revisions from being added. Try to avoid this
				# by trying less at a time from the last point.
				if ( $chunkSize <= 1 ) {
					// done!
					break;
				}
				$chunkSize = max( 1, floor( $chunkSize / 4 ) );
				continue;
			} else {
				$start += $chunkSize;
			}
			if ( !is_array( $log ) ) {
				// @TODO: cleanup :)
				var_dump( $log );
				$this->fatalError( 'Log entry is not an array! See content above.' );
			}
			foreach ( $log as $data ) {
				$revCount++;
				$delta = microtime( true ) - $startTime;
				$revSpeed = $revCount / $delta;

				$codeRev = CodeRevision::newFromSvn( $repo, $data );
				$codeRev->save();

				$this->output( sprintf( "%d %s %s (%0.1f revs/sec)\n",
					$codeRev->getId(),
					wfTimestamp( TS_DB, $codeRev->getTimestamp() ),
					$codeRev->getAuthor(),
					$revSpeed ) );
			}
			$lbFactory->waitForReplication( [ 'ifWritesSince' => 5 ] );
		}

		if ( $cacheSize !== 0 ) {
			$dbw = $this->getDB( DB_PRIMARY );
			$options = [ 'ORDER BY' => 'cr_id DESC' ];

			if ( $cacheSize == 'all' ) {
				$this->output( "Pre-caching all uncached diffs...\n" );
			} else {
				if ( $cacheSize == 1 ) {
					$this->output( "Pre-caching the latest diff...\n" );
				} else {
					$this->output( "Pre-caching the latest $cacheSize diffs...\n" );
				}
				$options['LIMIT'] = $cacheSize;
			}

			// Get all rows for this repository that don't already have a diff filled in.
			// This is LIMITed according to the $cacheSize setting, above, so only the
			// rows that we plan to pre-cache are returned.
			// TODO: This was optimised in order to skip rows that already have a diff,
			// which is mostly what is required, but there may be situations where
			// you want to re-calculate diffs (e.g. if $wgCodeReviewMaxDiffPaths
			// changes). If these situations arise we will either want to revert
			// this behavior, or add a --force flag or something.
			$res = $dbw->select(
				'code_rev',
				'cr_id',
				[ 'cr_repo_id' => $repo->getId(), 'cr_diff IS NULL OR cr_diff = ""' ],
				__METHOD__,
				$options
			);
			foreach ( $res as $row ) {
				$repo->getRevision( $row->cr_id );
				// trigger caching
				$diff = $repo->getDiff( $row->cr_id );
				$msg = "Diff r{$row->cr_id} ";
				if ( is_int( $diff ) ) {
					$msg .= 'Skipped: ' . CodeRepository::getDiffErrorMessage( $diff );
				} else {
					$msg .= 'done';
				}
				$this->output( $msg . "\n" );
			}
		} else {
			$this->output( "Pre-caching skipped.\n" );
		}
		$this->output( "Done!\n" );
	}
}

$maintClass = SvnImport::class;
require_once RUN_MAINTENANCE_IF_MAIN;
