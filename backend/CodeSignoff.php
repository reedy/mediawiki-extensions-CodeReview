<?php
/**
 * Class representing a sign-off. A sign-off in this context is the record of a
 * certain user having signed off on a certain revision with a certain flag.
 * Signing off with multiple flags at once creates multiple sign-offs.
 */
class CodeSignoff {
	/** CodeRevision object for the revision that was signed off on */
	public $rev;
	/** User ID (on the wiki, not in SVN) of the user that signed off */
	public $user;
	/** User name of the user that signed off */
	public $userText;
	/** Sign-off flag. See CodeRevision::getPossibleFlags() for possible values */
	public $flag;
	/** Timestamp of the sign-off, in TS_MW format */
	public $timestamp;
	
	private $timestampStruck;
	
	/**
	 * This constructor is only used by newFrom*(). You should not create your own
	 * CodeSignoff objects, they'll be useless if they don't correspond to existing entries
	 * in the database.
	 *
	 * For more detailed explanations of what each of the parameters mean, see public members.
	 * @param $rev CodeRevision object
	 * @param $user int User ID
	 * @param $userText string User name
	 * @param $flag string Flag
	 * @param $timestamp string TS_MW timestamp
	 * @param $timestampStruck string Raw (unformatted!) timestamp from the cs_timestamp_struck DB field
	 */
	public function __construct( $rev, $user, $userText, $flag, $timestamp, $timestampStruck ) {
		$this->rev = $rev;
		$this->user = $user;
		$this->userText = $userText;
		$this->flag = $flag;
		$this->timestamp = $timestamp;
		$this->timestampStruck = $timestampStruck;
	}
	
	/**
	 * @return bool Whether this sign-off has been struck
	 */
	public function isStruck() {
		return $this->timestampStruck !== Block::infinity();
	}
	
	/**
	 * @return mixed Timestamp (TS_MW format) the revision was struck at, or false if it hasn't been struck
	 */
	public function getTimestampStruck() {
		return $this->isStruck() ? wfTimestamp( TS_MW, $this->timestampStruck ) : false;
	}
	
	/**
	 * Strike this sign-off. Attempts to strike an already-struck signoff will be silently ignored.
	 */
	public function strike() {
		if ( $this->isStruck() ) {
			return;
		}
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'code_signoffs', array( 'cs_timestamp_struck' => $dbw->timestamp() ),
			array(
				'cs_repo_id' => $this->rev->getRepoId(),
				'cs_rev_id' => $this->rev->getId(),
				'cs_flag' => $this->flag,
				'cs_user_text' => $this->userText,
				'cs_timestamp_struck' => $this->timestampStruck
			), __METHOD__
		);
	}
	
	/**
	 * Get the ID of this signoff. This is not a numerical ID that exists in the database,
	 * but a representation that you can use in URLs and the like. It's also not unique:
	 * only the combination of a signoff ID and a revision is unique. You can obtain
	 * a CodeSignoff object from its ID and its revision with newFromID().
	 *
	 * @return string ID
	 */
	public function getID() {
		return implode( '|', array( $this->flag, $this->timestampStruck, $this->userText ) );
	}
	
	/**
	 * Create a CodeSignoff object from a revision and a database row object
	 * @param $rev CodeRevision object the signoff belongs to
	 * @param $row object Database row with cs_* fields from code_signoffs
	 * @return CodeSignoff
	 */
	public static function newFromRow( $rev, $row ) {
		return self::newFromData( $rev, get_object_vars( $row ) );
	}
	
	/**
	 * Create a CodeSignoff object from a revision and a database row in array format
	 * @param $rev CodeRevision object the signoff belongs to
	 * @param $row array Database row with cs_* fields from code_signoffs
	 * @return CodeSignoff
	 */
	public static function newFromData( $rev, $data ) {
		return new self( $rev, $data['cs_user'], $data['cs_user_text'], $data['cs_flag'],
			wfTimestamp( TS_MW, $data['cs_timestamp'] ), $data['cs_timestamp_struck']
		);
	}
	
	/**
	 * Create a CodeSignoff object from a revision object and an ID previously obtained from getID()
	 * @param $rev CodeRevision object
	 * @param $id string ID generated by getID()
	 * @return CodeSignoff
	 */
	public static function newFromID( $rev, $id ) {
		$parts = explode( '|', $id, 3 );
		if ( count( $parts ) != 3 ) {
			return null;
		}
		$dbr = wfGetDB( DB_SLAVE );
		$row = $dbr->selectRow( 'code_signoffs',
			array( 'cs_user', 'cs_user_text', 'cs_flag', 'cs_timestamp', 'cs_timestamp_struck' ),
			array(
				'cs_repo_id' => $rev->getRepoId(),
				'cs_rev_id' => $rev->getId(),
				'cs_flag' => $parts[0],
				'cs_timestamp_struck' => $parts[1],
				'cs_user_text' => $parts[2]
			), __METHOD__
		);
		if ( !$row ) {
			return null;
		}
		return self::newFromRow( $rev, $row );
	}
}
