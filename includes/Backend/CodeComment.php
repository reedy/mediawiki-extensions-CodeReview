<?php

namespace MediaWiki\Extension\CodeReview\Backend;

use MediaWiki\MediaWikiServices;
use stdClass;

/**
 * Represents a comment made to a revision.
 */
class CodeComment {
	public int $id;

	public string $text;

	public int $user;

	public string $userText;

	public string $timestamp;

	public string $sortkey;

	public array $attrib;

	public array $removed;

	public array $added;

	public CodeRevision $rev;

	/**
	 * @param CodeRevision $rev
	 */
	public function __construct( $rev ) {
		$this->rev = $rev;
	}

	/**
	 * @param CodeRevision $rev
	 * @param stdClass $row
	 * @return CodeComment
	 */
	public static function newFromRow( $rev, $row ) {
		return self::newFromData( $rev, get_object_vars( $row ) );
	}

	/**
	 * Create a comment given its ID _AND_ a repository
	 * @param int $cc_id Comment ID in the database
	 * @param CodeRevision $rev A revision object to which the comment is
	 * attached
	 * @return CodeComment
	 */
	public static function newFromID( $cc_id, CodeRevision $rev ) {
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );
		$row = $dbr->selectRow(
			'code_comment',
			[
				# fields needed to build a CodeRevision
				'cc_rev_id',
				'cc_repo_id',

				# fields needed for self::newFromRow()
				'cc_id',
				'cc_text',
				'cc_user',
				'cc_user_text',
				'cc_patch_line',
				'cc_timestamp',
				'cc_sortkey'
			],
			[ 'cc_id' => (int)$cc_id ],
			__METHOD__
		);
		return self::newFromRow( $rev, $row );
	}

	/**
	 * @param CodeRevision $rev
	 * @param array $data
	 * @return CodeComment
	 */
	public static function newFromData( $rev, $data ) {
		$comment = new CodeComment( $rev );
		$comment->id = intval( $data['cc_id'] );
		// @todo FIXME
		$comment->text = $data['cc_text'];
		$comment->user = $data['cc_user'];
		$comment->userText = $data['cc_user_text'];
		$comment->timestamp = wfTimestamp( TS_MW, $data['cc_timestamp'] );
		$comment->sortkey = $data['cc_sortkey'];
		return $comment;
	}

	/**
	 * @return int
	 */
	public function threadDepth() {
		$timestamps = explode( ',', $this->sortkey );
		return count( $timestamps );
	}
}
