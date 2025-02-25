<?php

namespace MediaWiki\Extension\CodeReview\Backend;

use Exception;
use FormattedRCFeed;
use IRCColourfulRCFeedFormatter;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\Authority;
use SpecialPage;
use stdClass;
use Title;
use User;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;

class CodeRevision {
	/**
	 * Regex to match bug mentions in comments, commit summaries, etc
	 *
	 * Examples:
	 * bug 1234, bug1234, bug #1234, bug#1234
	 */
	public const BUG_REFERENCE = '/\bbug ?#?(\d+)\b/i';

	protected CodeRepository $repo;

	protected int $repoId;

	protected int $id;

	protected string $author;

	protected string $timestamp;

	protected string $message;

	protected array $paths = [];

	protected string $status;

	protected string $oldStatus;

	protected string $commonPath;

	/**
	 * @param CodeRepository $repo
	 * @param array $data
	 * @return CodeRevision
	 */
	public static function newFromSvn( CodeRepository $repo, $data ) {
		$rev = new CodeRevision();
		$rev->repoId = $repo->getId();
		$rev->repo = $repo;
		$rev->id = intval( $data['rev'] );
		$rev->author = $data['author'];
		$rev->timestamp = wfTimestamp( TS_MW, strtotime( $data['date'] ) );
		$rev->message = rtrim( $data['msg'] );
		$rev->paths = $data['paths'];
		$rev->status = 'new';
		$rev->oldStatus = '';

		$common = null;
		if ( $rev->paths ) {
			if ( count( $rev->paths ) == 1 ) {
				$common = $rev->paths[0]['path'];
			} else {
				$first = array_shift( $rev->paths );
				$common = explode( '/', $first['path'] ?? '' );

				foreach ( $rev->paths as $path ) {
					$compare = explode( '/', $path['path'] ?? '' );

					// make sure $common is the shortest path
					if ( count( $compare ) < count( $common ) ) {
						[ $compare, $common ] = [ $common, $compare ];
					}

					$tmp = [];
					foreach ( $common as $k => $v ) {
						if ( $v == $compare[$k] ) {
							$tmp[] = $v;
						} else {
							break;
						}
					}
					$common = $tmp;
				}
				$common = implode( '/', $common );

				array_unshift( $rev->paths, $first );
			}

			$rev->paths = self::getPathFragments( $rev->paths );
		}
		$rev->commonPath = $common;

		// Check for ignored paths
		global $wgCodeReviewDeferredPaths;
		if ( isset( $wgCodeReviewDeferredPaths[$repo->getName()] ) ) {
			foreach ( $wgCodeReviewDeferredPaths[$repo->getName()] as $defer ) {
				if ( preg_match( $defer, $rev->commonPath ) ) {
					$rev->status = 'deferred';
					break;
				}
			}
		}

		global $wgCodeReviewAutoTagPath;
		if ( isset( $wgCodeReviewAutoTagPath[$repo->getName()] ) ) {
			foreach ( $wgCodeReviewAutoTagPath[$repo->getName()] as $path => $tags ) {
				if ( preg_match( $path, $rev->commonPath ) ) {
					$rev->changeTags( $tags, [] );
					break;
				}
			}
		}
		return $rev;
	}

	/**
	 * @param array $paths
	 * @return array
	 */
	public static function getPathFragments( $paths = [] ) {
		$allPaths = [];

		foreach ( $paths as $path ) {
			$currentPath = '/';
			foreach ( explode( '/', $path['path'] ) as $fragment ) {
				if ( $currentPath !== '/' ) {
					$currentPath .= '/';
				}

				$currentPath .= $fragment;

				if ( $currentPath == $path['path'] ) {
					$action = $path['action'];
				} else {
					$action = 'N';
				}

				$allPaths[] = [
					'path' => $currentPath,
					'action' => $action
				];
			}
		}

		return $allPaths;
	}

	/**
	 * @throws Exception
	 * @param CodeRepository $repo
	 * @param stdClass $row
	 * @return CodeRevision
	 */
	public static function newFromRow( CodeRepository $repo, $row ) {
		$rev = new CodeRevision();
		$rev->repoId = intval( $row->cr_repo_id );
		if ( $rev->repoId != $repo->getId() ) {
			throw new Exception( 'Invalid repo ID in ' . __METHOD__ );
		}
		$rev->repo = $repo;
		$rev->id = intval( $row->cr_id );
		$rev->author = $row->cr_author;
		$rev->timestamp = wfTimestamp( TS_MW, $row->cr_timestamp );
		$rev->message = $row->cr_message;
		$rev->status = $row->cr_status;
		$rev->oldStatus = '';
		$rev->commonPath = $row->cr_path;
		return $rev;
	}

	/**
	 * @return int
	 */
	public function getId() {
		return $this->id;
	}

	/**
	 * Like getId(), but returns the result as a string, including prefix,
	 * i.e. "r123" instead of 123.
	 * @param int|null $id
	 * @return string
	 */
	public function getIdString( $id = null ) {
		if ( $id === null ) {
			$id = $this->getId();
		}
		return $this->repo->getRevIdString( $id );
	}

	/**
	 * Like getIdString(), but if more than one repository is defined
	 * on the wiki then it includes the repo name as a prefix to the revision ID
	 * (separated with a period).
	 * This ensures you get a unique reference, as the revision ID alone can be
	 * confusing (e.g. in emails, page titles etc.). If only one repository is
	 * defined then this returns the same as getIdString() as there is no ambiguity.
	 *
	 * @param int|null $id
	 * @return string
	 */
	public function getIdStringUnique( $id = null ) {
		if ( $id === null ) {
			$id = $this->getId();
		}
		return $this->repo->getRevIdStringUnique( $id );
	}

	/**
	 * @return int
	 */
	public function getRepoId() {
		return $this->repoId;
	}

	/**
	 * @return CodeRepository
	 */
	public function getRepo() {
		return $this->repo;
	}

	/**
	 * @return string
	 */
	public function getAuthor() {
		return $this->author;
	}

	/**
	 * @return User
	 */
	public function getWikiUser() {
		return $this->repo->authorWikiUser( $this->getAuthor() );
	}

	/**
	 * @return string
	 */
	public function getTimestamp() {
		return $this->timestamp;
	}

	/**
	 * @return string
	 */
	public function getMessage() {
		return $this->message;
	}

	/**
	 * @return string
	 */
	public function getStatus() {
		return $this->status;
	}

	/**
	 * @return string
	 */
	public function getOldStatus() {
		return $this->oldStatus;
	}

	/**
	 * @return string
	 */
	public function getCommonPath() {
		return $this->commonPath;
	}

	/**
	 * List of all possible states a CodeRevision can be in
	 * @return array
	 */
	public static function getPossibleStates() {
		global $wgCodeReviewStates;
		return $wgCodeReviewStates;
	}

	/**
	 * List of all states that a user cannot set on their own revision
	 * @return array
	 */
	public static function getProtectedStates() {
		global $wgCodeReviewProtectedStates;
		return $wgCodeReviewProtectedStates;
	}

	/**
	 * @return array
	 */
	public static function getPossibleStateMessageKeys() {
		return array_map( [ self::class, 'makeStateMessageKey' ], self::getPossibleStates() );
	}

	/**
	 * @param string $key
	 * @return string
	 */
	private static function makeStateMessageKey( $key ) {
		return "code-status-$key";
	}

	/**
	 * List of all flags a user can mark themselves as having done to a revision
	 * @return array
	 */
	public static function getPossibleFlags() {
		global $wgCodeReviewFlags;
		return $wgCodeReviewFlags;
	}

	/**
	 * Returns whether the provided status is valid
	 * @param string $status
	 * @return bool
	 */
	public static function isValidStatus( $status ) {
		return in_array( $status, self::getPossibleStates(), true );
	}

	/**
	 * Returns whether the provided status is protected
	 * @param string $status
	 * @return bool
	 */
	public static function isProtectedStatus( $status ) {
		return in_array( $status, self::getProtectedStates(), true );
	}

	/**
	 * @throws Exception
	 * @param string $status value in CodeRevision::getPossibleStates
	 * @param Authority $performer
	 * @return bool
	 */
	public function setStatus( $status, $performer ) {
		if ( !self::isValidStatus( $status ) ) {
			throw new Exception( 'Tried to save invalid code revision status' );
		}

		// Don't allow the user account tied to the committer account mark
		// their own revisions as ok/resolved
		// Obviously, this only works if user accounts are tied!
		$wikiUser = $this->getWikiUser();
		if (
			self::isProtectedStatus( $status ) &&
			$wikiUser &&
			$performer->getUser()->getName() == $wikiUser->getName() &&
			// allow the user to review their own code if required
			!$wikiUser->isAllowed( 'codereview-review-own' )
		) {
			return false;
		}

		// Get the old status from the primary database
		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );
		$this->oldStatus = $dbw->selectField(
			'code_rev',
			'cr_status',
			[ 'cr_repo_id' => $this->repoId, 'cr_id' => $this->id ],
			__METHOD__
		);
		if ( $this->oldStatus === $status ) {
			// nothing to do here
			return false;
		}
		// Update status
		$this->status = $status;
		$dbw->update(
			'code_rev',
			[ 'cr_status' => $status ],
			[
				'cr_repo_id' => $this->repoId,
				'cr_id' => $this->id
			],
			__METHOD__
		);
		// Log this change
		if ( $performer && $performer->getUser()->getId() ) {
			$dbw->insert(
				'code_prop_changes',
				[
					'cpc_repo_id'   => $this->getRepoId(),
					'cpc_rev_id'    => $this->getId(),
					'cpc_attrib'    => 'status',
					'cpc_removed'   => $this->oldStatus,
					'cpc_added'     => $status,
					'cpc_timestamp' => $dbw->timestamp(),
					'cpc_user'      => $performer->getUser()->getId(),
					'cpc_user_text' => $performer->getUser()->getName()
				],
				__METHOD__
			);
		}

		$this->sendStatusToUDP( $status, $this->oldStatus, $performer );

		return true;
	}

	/**
	 * Quickie protection against huuuuuuuuge batch inserts
	 *
	 * @param IDatabase $db
	 * @param string $table
	 * @param array $data
	 * @param string $method
	 * @param array $options
	 * @return void
	 */
	protected static function insertChunks(
		$db, $table, $data, $method = __METHOD__, $options = []
	) {
		$chunkSize = 100;
		for ( $i = 0, $count = count( $data ); $i < $count; $i += $chunkSize ) {
			$db->insert(
				$table,
				array_slice( $data, $i, $chunkSize ),
				$method,
				$options
			);
		}
	}

	public function save() {
		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );
		$dbw->startAtomic( __METHOD__ );
		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();

		$dbw->insert(
			'code_rev',
			[
				'cr_repo_id' => $this->repoId,
				'cr_id' => $this->id,
				'cr_author' => $this->author,
				'cr_timestamp' => $dbw->timestamp( $this->timestamp ),
				'cr_message' => $this->message,
				'cr_status' => $this->status,
				'cr_path' => $this->commonPath,
				'cr_flags' => ''
			],
			__METHOD__,
			[ 'IGNORE' ]
		);

		// Already exists? Update the row!
		$newRevision = $dbw->affectedRows() > 0;
		if ( !$newRevision ) {
			$dbw->update(
				'code_rev',
				[
					'cr_author' => $this->author,
					'cr_timestamp' => $dbw->timestamp( $this->timestamp ),
					'cr_message' => $this->message,
					'cr_path' => $this->commonPath
				],
				[
					'cr_repo_id' => $this->repoId,
					'cr_id' => $this->id
				],
				__METHOD__
			);
		}

		// Update path tracking used for output and searching
		if ( $this->paths ) {
			self::insertPaths( $dbw, $this->paths, $this->repoId, $this->id );
		}

		$affectedRevs = $this->getUniqueAffectedRevs();

		if ( count( $affectedRevs ) ) {
			$this->addReferencesTo( $affectedRevs );
		}

		global $wgEnableEmail, $wgCodeReviewDisableFollowUpNotification;
		// Email the authors of revisions that this follows up on
		if ( $wgEnableEmail && !$wgCodeReviewDisableFollowUpNotification
			&& $newRevision && count( $affectedRevs ) > 0
		) {
			// Get committer wiki username, or repo name at least
			$commitAuthor = $this->getWikiUser();

			if ( $commitAuthor ) {
				$committer = $commitAuthor->getName();
				$commitAuthorId = $commitAuthor->getId();
			} else {
				$committer = htmlspecialchars( $this->author );
				$commitAuthorId = 0;
			}

			// Get the authors of these revisions
			$res = $dbw->select(
				'code_rev',
				[
					'cr_repo_id',
					'cr_id',
					'cr_author',
					'cr_timestamp',
					'cr_message',
					'cr_status',
					'cr_path',
				],
				[
					'cr_repo_id' => $this->repoId,
					'cr_id'      => $affectedRevs,
					// just in case
					'cr_id < ' . $this->id,
					// No sense in notifying if it's the same person
					'cr_author != ' . $dbw->addQuotes( $this->author )
				],
				__METHOD__,
				[ 'USE INDEX' => 'PRIMARY' ]
			);

			// Get repo and build comment title (for url)
			$url = $this->getCanonicalUrl();

			foreach ( $res as $row ) {
				$revision = self::newFromRow( $this->repo, $row );
				$users = $revision->getCommentingUsers();

				$rowUrl = $revision->getCanonicalUrl();

				$revisionAuthor = $revision->getWikiUser();

				$revisionCommitSummary = $revision->getMessage();

				// Add the followup revision author if they have not already
				// been added as a commentor (they won't want dupe emails!)
				if ( $revisionAuthor && !array_key_exists( $revisionAuthor->getId(), $users ) ) {
					$users[$revisionAuthor->getId()] = $revisionAuthor;
				}

				// Notify commenters and revision author of followup revision
				foreach ( $users as $user ) {
					/**
					 * @var $user User
					 */

					// No sense in notifying the author of this rev if they are
					// a commenter/the author on the target rev
					if ( $commitAuthorId == $user->getId() ) {
						continue;
					}

					if ( $user->canReceiveEmail() ) {
						// Send message in receiver's language
						$lang = $userOptionsLookup->getOption( $user, 'language' );
						$user->sendMail(
							wfMessage( 'codereview-email-subj2', $this->repo->getName(),
								$this->getIdString( $row->cr_id ) )->inLanguage( $lang )->text(),
							wfMessage( 'codereview-email-body2', $committer,
								$this->getIdStringUnique( $row->cr_id ),
								$url, $this->message,
								$rowUrl, $revisionCommitSummary )->inLanguage( $lang )->text()
						);
					}
				}
			}
		}

		$dbw->endAtomic( __METHOD__ );
	}

	/**
	 * @param IDatabase $dbw
	 * @param array $paths
	 * @param int $repoId
	 * @param int $revId
	 */
	public static function insertPaths( $dbw, $paths, $repoId, $revId ) {
		$data = [];
		foreach ( $paths as $path ) {
			$data[] = [
				'cp_repo_id' => $repoId,
				'cp_rev_id'  => $revId,
				'cp_path'    => $path['path'],
				'cp_action'  => $path['action']
			];
		}
		self::insertChunks( $dbw, 'code_paths', $data, __METHOD__, [ 'IGNORE' ] );
	}

	/**
	 * Returns a unique value array from that of getAffectedRevs() and getAffectedBugRevs()
	 *
	 * @return array
	 */
	public function getUniqueAffectedRevs() {
		return array_unique( array_merge( $this->getAffectedRevs(), $this->getAffectedBugRevs() ) );
	}

	/**
	 * Get the revisions this commit references
	 *
	 * @return array
	 */
	public function getAffectedRevs() {
		$affectedRevs = [];
		$m = [];
		if ( preg_match_all( '/\br(\d{2,})\b/', $this->message, $m ) ) {
			foreach ( $m[1] as $rev ) {
				$affectedRev = intval( $rev );
				if ( $affectedRev != $this->id ) {
					$affectedRevs[] = $affectedRev;
				}
			}
		}
		return $affectedRevs;
	}

	/**
	 * Parses references bugs in the comment, inserts them to code bugs, and returns an array of
	 * previous revs linking to the same bug
	 *
	 * @return array
	 */
	public function getAffectedBugRevs() {
		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );

		// Update bug references table...
		$affectedBugs = [];
		$m = [];
		if ( preg_match_all( self::BUG_REFERENCE, $this->message, $m ) ) {
			$data = [];
			foreach ( $m[1] as $bug ) {
				$data[] = [
					'cb_repo_id' => $this->repoId,
					'cb_from'    => $this->id,
					'cb_bug'     => $bug
				];
				$affectedBugs[] = intval( $bug );
			}
			$dbw->insert( 'code_bugs', $data, __METHOD__, [ 'IGNORE' ] );
		}

		// Also, get previous revisions that have bugs in common...
		$affectedRevs = [];
		if ( count( $affectedBugs ) ) {
			$res = $dbw->select(
				'code_bugs',
				[ 'cb_from' ],
				[
					'cb_repo_id' => $this->repoId,
					'cb_bug'     => $affectedBugs,
					// just in case
					'cb_from < ' . $this->id,
				],
				__METHOD__,
				[ 'USE INDEX' => 'cb_repo_id' ]
			);
			foreach ( $res as $row ) {
				$affectedRevs[] = intval( $row->cb_from );
			}
		}

		return $affectedRevs;
	}

	/**
	 * @return IResultWrapper
	 */
	public function getModifiedPaths() {
		return MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA )->select(
			'code_paths',
			[ 'cp_path', 'cp_action' ],
			[ 'cp_repo_id' => $this->repoId, 'cp_rev_id' => $this->id ],
			__METHOD__
		);
	}

	/**
	 * @return bool
	 */
	public function isDiffable() {
		global $wgCodeReviewMaxDiffPaths;
		$paths = $this->getModifiedPaths();
		return $paths->numRows()
			&& ( $wgCodeReviewMaxDiffPaths > 0 && $paths->numRows() < $wgCodeReviewMaxDiffPaths );
	}

	/**
	 * @param string $text
	 * @param Authority $performer
	 * @param null $parent
	 * @return CodeComment
	 */
	public function previewComment( $text, Authority $performer, $parent = null ) {
		$data = $this->commentData( rtrim( $text ), $performer, $parent );
		$data['cc_id'] = null;
		return CodeComment::newFromData( $this, $data );
	}

	/**
	 * @param string $text
	 * @param Authority $performer
	 * @param null $parent
	 * @return int
	 */
	public function saveComment( $text, Authority $performer, $parent = null ) {
		$text = rtrim( $text );
		if ( !strlen( $text ) ) {
			return 0;
		}
		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );
		$data = $this->commentData( $text, $performer, $parent );

		$dbw->startAtomic( __METHOD__ );
		$dbw->insert( 'code_comment', $data, __METHOD__ );
		$commentId = $dbw->insertId();
		$dbw->endAtomic( __METHOD__ );

		$url = $this->getCanonicalUrl( $commentId );

		$this->sendCommentToUDP( $commentId, $text, $performer, $url );

		return $commentId;
	}

	/**
	 * @param Authority $performer Whoever made the changes
	 * @param string $subject
	 * @param string $body
	 * @param string|array ...$args
	 * @return void
	 */
	public function emailNotifyUsersOfChanges( Authority $performer, $subject, $body, ...$args ) {
		// Give email notices to committer and commenters
		global $wgCodeReviewENotif, $wgEnableEmail, $wgCodeReviewCommentWatcherEmail,
			$wgCodeReviewCommentWatcherName;
		$userOptionsLookup = MediaWikiServices::getInstance()->getUserOptionsLookup();
		if ( !$wgCodeReviewENotif || !$wgEnableEmail ) {
			return;
		}

		// Make list of users to send emails to
		$users = $this->getCommentingUsers();
		$wikiUser = $this->getWikiUser();
		if ( $wikiUser ) {
			$users[$wikiUser->getId()] = $wikiUser;
		}
		// If we've got a spam list, send emails to it too
		if ( $wgCodeReviewCommentWatcherEmail ) {
			$watcher = new User();
			$watcher->setEmail( $wgCodeReviewCommentWatcherEmail );
			$watcher->setName( $wgCodeReviewCommentWatcherName );
			// We don't have any anons, so using 0 is safe
			$users[0] = $watcher;
		}

		/**
		 * @var $user User
		 */
		foreach ( $users as $id => $user ) {
			// No sense in notifying this commenter
			if ( $wikiUser->getId() == $user->getId() ) {
				continue;
			}

			// canReceiveEmail() returns false for the fake watcher user, so exempt it
			// This is ugly
			if ( $id == 0 || $user->canReceiveEmail() ) {
				// Send a message in receiver's language
				$lang = $userOptionsLookup->getOption( $user, 'language' );

				$localSubject = wfMessage( $subject, $this->repo->getName(), $this->getIdString() )
					->inLanguage( $lang )->text();
				$localBody = wfMessage( $body, $args )->inLanguage( $lang )->text();

				$user->sendMail( $localSubject, $localBody );
			}
		}
	}

	/**
	 * @param string $text
	 * @param Authority $performer
	 * @param null $parent
	 * @return array
	 */
	protected function commentData( $text, Authority $performer, $parent = null ) {
		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );
		$ts = wfTimestamp( TS_MW );

		return [
			'cc_repo_id' => $this->repoId,
			'cc_rev_id' => $this->id,
			'cc_text' => $text,
			'cc_parent' => $parent,
			'cc_user' => $performer->getUser()->getId(),
			'cc_user_text' => $performer->getUser()->getName(),
			'cc_timestamp' => $dbw->timestamp( $ts ),
			'cc_sortkey' => $this->threadedSortkey( $parent, $ts )
		];
	}

	/**
	 * @throws Exception
	 * @param null $parent
	 * @param string $ts
	 * @return string
	 */
	protected function threadedSortKey( $parent, $ts ) {
		if ( $parent ) {
			// We construct a threaded sort key by concatenating the timestamps
			// of all our parent comments
			$dbw = MediaWikiServices::getInstance()
				->getDBLoadBalancer()
				->getMaintenanceConnectionRef( DB_PRIMARY );
			$parentKey = $dbw->selectField(
				'code_comment',
				'cc_sortkey',
				[ 'cc_id' => $parent ],
				__METHOD__
			);
			if ( $parentKey ) {
				return $parentKey . ',' . $ts;
			} else {
				// hmmmm
				throw new Exception( 'Invalid parent submission' );
			}
		} else {
			return $ts;
		}
	}

	/**
	 * @return array
	 */
	public function getComments() {
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );
		$result = $dbr->select(
			'code_comment',
			[
				'cc_id',
				'cc_text',
				'cc_user',
				'cc_user_text',
				'cc_timestamp',
				'cc_sortkey' ],
			[
				'cc_repo_id' => $this->repoId,
				'cc_rev_id' => $this->id
			],
			__METHOD__,
			[ 'ORDER BY' => 'cc_sortkey' ]
		);
		$comments = [];
		foreach ( $result as $row ) {
			$comments[] = CodeComment::newFromRow( $this, $row );
		}
		return $comments;
	}

	/**
	 * @return int
	 */
	public function getCommentCount() {
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );
		return $dbr->selectRowCount(
			'code_comment',
			[ 'cc_id' ],
			[
				'cc_repo_id' => $this->repoId,
				'cc_rev_id' => $this->id
			],
			__METHOD__
		);
	}

	/**
	 * @return array
	 */
	public function getPropChanges() {
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );
		$result = $dbr->select(
			[ 'code_prop_changes', 'user' ],
			[
				'cpc_attrib',
				'cpc_removed',
				'cpc_added',
				'cpc_timestamp',
				'cpc_user',
				'cpc_user_text',
				'user_name'
			], [
				'cpc_repo_id' => $this->repoId,
				'cpc_rev_id' => $this->id,
			],
			__METHOD__,
			[ 'ORDER BY' => 'cpc_timestamp DESC' ],
			[ 'user' => [ 'LEFT JOIN', 'cpc_user = user_id' ] ]
		);
		$changes = [];
		foreach ( $result as $row ) {
			$changes[] = CodePropChange::newFromRow( $this, $row );
		}
		return $changes;
	}

	/**
	 * @return array
	 */
	public function getPropChangeUsers() {
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );
		$result = $dbr->select(
			'code_prop_changes',
			'DISTINCT(cpc_user)',
			[
				'cpc_repo_id' => $this->repoId,
				'cpc_rev_id' => $this->id,
			],
			__METHOD__
		);
		$users = [];
		foreach ( $result as $row ) {
			$users[$row->cpc_user] = User::newFromId( $row->cpc_user );
		}
		return $users;
	}

	/**
	 * "Review" being revision commenters, and people who set/removed tags and changed the status
	 *
	 * @return array
	 */
	public function getReviewContributingUsers() {
		return array_merge( $this->getCommentingUsers(), $this->getPropChangeUsers() );
	}

	/**
	 * @return array
	 */
	protected function getCommentingUsers() {
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );
		$res = $dbr->select(
			'code_comment',
			'DISTINCT(cc_user)',
			[
				'cc_repo_id' => $this->repoId,
				'cc_rev_id' => $this->id,
				// users only
				'cc_user != 0'
			],
			__METHOD__
		);
		$users = [];
		foreach ( $res as $row ) {
			$users[$row->cc_user] = User::newFromId( $row->cc_user );
		}
		return $users;
	}

	/**
	 * Get all revisions referring to this revision (called followups of this revision in the UI).
	 *
	 * Any references from a revision to itself or from a revision to a revision in its past
	 * (i.e. with a lower revision ID) are silently dropped.
	 *
	 * @return array of code_rev database row objects
	 */
	public function getFollowupRevisions() {
		$refs = [];
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );
		$res = $dbr->select(
			[ 'code_relations', 'code_rev' ],
			[ 'cr_id', 'cr_status', 'cr_timestamp', 'cr_author', 'cr_message' ],
			[
				'cf_repo_id' => $this->repoId,
				'cf_to' => $this->id,
				'cr_repo_id = cf_repo_id',
				'cr_id = cf_from'
			],
			__METHOD__
		);
		foreach ( $res as $row ) {
			if ( $this->id < intval( $row->cr_id ) ) {
				$refs[] = $row;
			}
		}
		return $refs;
	}

	/**
	 * Get all revisions this revision follows up
	 *
	 * @return array of code_rev database row objects
	 */
	public function getFollowedUpRevisions() {
		$refs = [];
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );
		$res = $dbr->select(
			[ 'code_relations', 'code_rev' ],
			[ 'cr_id', 'cr_status', 'cr_timestamp', 'cr_author', 'cr_message' ],
			[
				'cf_repo_id' => $this->repoId,
				'cf_from' => $this->id,
				'cr_repo_id = cf_repo_id',
				'cr_id = cf_to'
			],
			__METHOD__
		);
		foreach ( $res as $row ) {
			if ( $this->id > intval( $row->cr_id ) ) {
				$refs[] = $row;
			}
		}
		return $refs;
	}

	/**
	 * Add references from the specified revisions to this revision. In the UI, this will
	 * show the specified revisions as follow-ups to this one.
	 *
	 * This function will silently refuse to add a reference from a revision to itself or from
	 * revisions in its past (i.e. with lower revision IDs)
	 * @param array $revs array of revision IDs
	 */
	public function addReferencesFrom( $revs ) {
		$data = [];
		foreach ( array_unique( (array)$revs ) as $rev ) {
			if ( $rev > $this->getId() ) {
				$data[] = [
					'cf_repo_id' => $this->getRepoId(),
					'cf_from' => $rev,
					'cf_to' => $this->getId()
				];
			}
		}
		$this->addReferences( $data );
	}

	/**
	 * @param array $data
	 */
	private function addReferences( $data ) {
		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );
		$dbw->insert( 'code_relations', $data, __METHOD__, [ 'IGNORE' ] );
	}

	/**
	 * Same as addReferencesFrom(), but adds references from this revision to
	 * the specified revisions.
	 * @param array $revs array of revision IDs
	 */
	public function addReferencesTo( $revs ) {
		$data = [];
		foreach ( array_unique( (array)$revs ) as $rev ) {
			if ( $rev < $this->getId() ) {
				$data[] = [
					'cf_repo_id' => $this->getRepoId(),
					'cf_from' => $this->getId(),
					'cf_to' => $rev,
				];
			}
		}
		$this->addReferences( $data );
	}

	/**
	 * Remove references from the specified revisions to this revision. In the UI, this will
	 * no longer show the specified revisions as follow-ups to this one.
	 * @param array $revs array of revision IDs
	 */
	public function removeReferencesFrom( $revs ) {
		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );
		$dbw->delete( 'code_relations', [
				'cf_repo_id' => $this->getRepoId(),
				'cf_from' => $revs,
				'cf_to' => $this->getId()
			], __METHOD__
		);
	}

	/**
	 * Remove references to the specified revisions from this revision.
	 *
	 * @param array $revs array of revision IDs
	 */
	public function removeReferencesTo( $revs ) {
		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );
		$dbw->delete( 'code_relations', [
				'cf_repo_id' => $this->getRepoId(),
				'cf_from' => $this->getId(),
				'cf_to' => $revs
			], __METHOD__
		);
	}

	/**
	 * Get all sign-offs for this revision
	 * @param int $from DB_REPLICA or DB_PRIMARY
	 * @return array of CodeSignoff objects
	 */
	public function getSignoffs( $from = DB_REPLICA ) {
		$db = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( $from );
		$result = $db->select(
			'code_signoffs',
			[ 'cs_user', 'cs_user_text', 'cs_flag', 'cs_timestamp', 'cs_timestamp_struck' ],
			[
				'cs_repo_id' => $this->repoId,
				'cs_rev_id' => $this->id,
			],
			__METHOD__,
			[ 'ORDER BY' => 'cs_timestamp' ]
		);

		$signoffs = [];
		foreach ( $result as $row ) {
			$signoffs[] = CodeSignoff::newFromRow( $this, $row );
		}
		return $signoffs;
	}

	/**
	 * Add signoffs for this revision
	 * @param Authority $performer Authority object for the user who did the sign-off
	 * @param array $flags array of flags (strings, see getPossibleFlags()). Each flag is added as
	 *   a separate sign-off
	 */
	public function addSignoff( $performer, $flags ) {
		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );
		$rows = [];
		$infinity = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA )->getInfinity();
		foreach ( (array)$flags as $flag ) {
			$rows[] = [
				'cs_repo_id' => $this->repoId,
				'cs_rev_id' => $this->id,
				'cs_user' => $performer->getUser()->getId(),
				'cs_user_text' => $performer->getUser()->getName(),
				'cs_flag' => $flag,
				'cs_timestamp' => $dbw->timestamp(),
				'cs_timestamp_struck' => $infinity,
			];
		}
		$dbw->insert( 'code_signoffs', $rows, __METHOD__, [ 'IGNORE' ] );
	}

	/**
	 * Strike a set of sign-offs by a given user. Any sign-offs in $ids not
	 * by $user are silently ignored, as well as nonexistent IDs and
	 * already-struck sign-offs.
	 * @param Authority $performer Authority object
	 * @param array $ids array of sign-off IDs to strike
	 */
	public function strikeSignoffs( $performer, $ids ) {
		foreach ( $ids as $id ) {
			$signoff = CodeSignoff::newFromId( $this, $id );
			// Only allow striking own signoffs
			if ( $signoff && $signoff->userText === $performer->getUser()->getName() ) {
				$signoff->strike();
			}
		}
	}

	/**
	 * @param int $from
	 * @return array
	 */
	public function getTags( $from = DB_REPLICA ) {
		$db = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( $from );
		$result = $db->select(
			'code_tags',
			[ 'ct_tag' ],
			[
				'ct_repo_id' => $this->repoId,
				'ct_rev_id' => $this->id
			],
			__METHOD__
		);

		$tags = [];
		foreach ( $result as $row ) {
			$tags[] = $row->ct_tag;
		}
		return $tags;
	}

	/**
	 * @param array $addTags
	 * @param array $removeTags
	 * @param Authority|null $performer
	 */
	public function changeTags( $addTags, $removeTags, $performer = null ) {
		// Get the current tags and see what changes
		$tagsNow = $this->getTags( DB_PRIMARY );
		// Normalize our input tags
		$addTags = $this->normalizeTags( $addTags );
		$removeTags = $this->normalizeTags( $removeTags );
		$addTags = array_diff( $addTags, $tagsNow );
		$removeTags = array_intersect( $removeTags, $tagsNow );
		// Do the queries
		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_PRIMARY );
		if ( $addTags ) {
			$dbw->insert(
				'code_tags',
				$this->tagData( $addTags ),
				__METHOD__,
				[ 'IGNORE' ]
			);
		}
		if ( $removeTags ) {
			$dbw->delete(
				'code_tags',
				[
					'ct_repo_id' => $this->repoId,
					'ct_rev_id'  => $this->id,
					'ct_tag'     => $removeTags ],
				__METHOD__
			);
		}
		// Log this change
		if ( ( $removeTags || $addTags ) && $performer && $performer->getUser()->getId() ) {
			$dbw->insert( 'code_prop_changes',
				[
					'cpc_repo_id'   => $this->getRepoId(),
					'cpc_rev_id'    => $this->getId(),
					'cpc_attrib'    => 'tags',
					'cpc_removed'   => implode( ',', $removeTags ),
					'cpc_added'     => implode( ',', $addTags ),
					'cpc_timestamp' => $dbw->timestamp(),
					'cpc_user'      => $performer->getUser()->getId(),
					'cpc_user_text' => $performer->getUser()->getName()
				],
				__METHOD__
			);
		}
	}

	/**
	 * @param array $tags
	 * @return array
	 */
	protected function normalizeTags( $tags ) {
		$out = [];
		foreach ( $tags as $tag ) {
			$out[] = $this->normalizeTag( $tag );
		}
		return $out;
	}

	/**
	 * @param array $tags
	 * @return array
	 */
	protected function tagData( $tags ) {
		$data = [];
		foreach ( $tags as $tag ) {
			if ( $tag == '' ) {
				continue;
			}
			$data[] = [
				'ct_repo_id' => $this->repoId,
				'ct_rev_id'  => $this->id,
				'ct_tag'     => $this->normalizeTag( $tag ) ];
		}
		return $data;
	}

	/**
	 * @param string $tag
	 * @return bool
	 */
	public function normalizeTag( $tag ) {
		$title = Title::newFromText( $tag );
		if ( $title ) {
			return MediaWikiServices::getInstance()->getContentLanguage()->lc( $title->getDBkey() );
		}

		return false;
	}

	/**
	 * @param string $tag
	 * @return bool
	 */
	public function isValidTag( $tag ) {
		return ( $this->normalizeTag( $tag ) !== false );
	}

	/**
	 * @param string $path
	 * @return bool|int
	 */
	public function getPrevious( $path = '' ) {
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );
		$encId = $dbr->addQuotes( $this->id );
		$tables = [ 'code_rev' ];
		if ( $path != '' ) {
			$conds = $this->getPathConds( $path );
			$order = 'cp_rev_id DESC';
			$tables[] = 'code_paths';
		} else {
			$conds = [ 'cr_repo_id' => $this->repoId ];
			$order = 'cr_id DESC';
		}
		$conds[] = "cr_id < $encId";
		$row = $dbr->selectRow(
			$tables,
			'cr_id',
			$conds,
			__METHOD__,
			[ 'ORDER BY' => $order ]
		);
		if ( $row ) {
			return intval( $row->cr_id );
		} else {
			return false;
		}
	}

	/**
	 * @param string $path
	 * @return bool|int
	 */
	public function getNext( $path = '' ) {
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );
		$encId = $dbr->addQuotes( $this->id );
		$tables = [ 'code_rev' ];
		if ( $path != '' ) {
			$conds = $this->getPathConds( $path );
			$order = 'cp_rev_id ASC';
			$tables[] = 'code_paths';
		} else {
			$conds = [ 'cr_repo_id' => $this->repoId ];
			$order = 'cr_id ASC';
		}
		$conds[] = "cr_id > $encId";
		$row = $dbr->selectRow(
			$tables,
			'cr_id',
			$conds,
			__METHOD__,
			[ 'ORDER BY' => $order ]
		);
		if ( $row ) {
			return intval( $row->cr_id );
		}

		return false;
	}

	/**
	 * @param string $path
	 * @return array
	 */
	protected function getPathConds( $path ) {
		return [
			'cp_repo_id' => $this->repoId,
			'cp_path' => $path,
			// join conds
			'cr_repo_id = cp_repo_id',
			'cr_id = cp_rev_id'
		];
	}

	/**
	 * @param string $path
	 * @return bool|int
	 */
	public function getNextUnresolved( $path = '' ) {
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );
		$encId = $dbr->addQuotes( $this->id );
		$tables = [ 'code_rev' ];
		if ( $path != '' ) {
			$conds = $this->getPathConds( $path );
			$order = 'cp_rev_id ASC';
			$tables[] = 'code_paths';
		} else {
			$conds = [ 'cr_repo_id' => $this->repoId ];
			$order = 'cr_id ASC';
		}
		$conds[] = "cr_id > $encId";
		$conds['cr_status'] = [ 'new', 'fixme' ];
		$row = $dbr->selectRow(
			$tables,
			'cr_id',
			$conds,
			__METHOD__,
			[ 'ORDER BY' => $order ]
		);
		if ( $row ) {
			return intval( $row->cr_id );
		}

		return false;
	}

	/**
	 * Get the canonical URL of a revision. Constructs a Title for this revision
	 * along the lines of [[Special:Code/RepoName/12345#c678]] and calls getCanonicalURL().
	 * @param string|int $commentId
	 * @return string
	 */
	public function getCanonicalUrl( $commentId = 0 ) {
		# Append comment ID if not null, empty string or zero
		$fragment = $commentId ? "c{$commentId}" : '';
		$title = SpecialPage::getTitleFor(
			'Code',
			$this->repo->getName() . '/' . $this->id,
			$fragment
		);

		return $title->getCanonicalURL();
	}

	/**
	 * @param string $commentId
	 * @param string $text
	 * @param Authority $performer
	 * @param null|string $url
	 * @return void
	 */
	protected function sendCommentToUDP( $commentId, $text, Authority $performer, $url = null ) {
		global $wgLang;
		if ( $url === null ) {
			$url = $this->getCanonicalUrl( $commentId );
		}

		$line = sprintf(
			"%s \00314(%s)\003 \0037%s\003 \00303%s\003: \00310%s\003%s",
			wfMessage( 'code-rev-message' )->text(),
			$this->repo->getName(),
			$this->getIdString(),
			IRCColourfulRCFeedFormatter::cleanupForIRC( $performer->getUser()->getName() ),
			IRCColourfulRCFeedFormatter::cleanupForIRC( $wgLang->truncateForVisual( $text, 100 ) ),
			$url
		);

		$this->sendRecentChanges( $line );
	}

	/**
	 * @param string $status
	 * @param string $oldStatus
	 * @param Authority $performer
	 */
	protected function sendStatusToUDP( $status, $oldStatus, Authority $performer ) {
		$url = $this->getCanonicalUrl();

		// Give grep a chance to find the usages:
		// code-status-new, code-status-fixme, code-status-reverted, code-status-resolved,
		// code-status-ok, code-status-deferred, code-status-old
		$line = sprintf(
			"%s \00314(%s)\00303 %s\003 %s: \00315%s\003 -> \00310%s\003%s",
			wfMessage( 'code-rev-status' )->text(),
			$this->repo->getName(),
			IRCColourfulRCFeedFormatter::cleanupForIRC( $performer->getUser()->getName() ),
			// Remove three apostrophes as they are intended for the parser
			str_replace(
				"'''",
				'',
				wfMessage(
					'code-change-status',
					"\0037{$this->getIdString()}\003"
				)->text()
			),
			wfMessage( 'code-status-' . $oldStatus )->text(),
			wfMessage( 'code-status-' . $status )->text(),
			$url
		);

		$this->sendRecentChanges( $line );
	}

	/**
	 * @param string $line
	 */
	private function sendRecentChanges( $line ) {
		global $wgCodeReviewRC;
		foreach ( $wgCodeReviewRC as $rc ) {
			/**
			 * @var FormattedRCFeed $engine
			 */
			$engine = new $rc['formatter'];
			$engine->send( $rc, $line );
		}
	}
}
