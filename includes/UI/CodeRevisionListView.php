<?php

namespace MediaWiki\Extension\CodeReview\UI;

use MediaWiki\Extension\CodeReview\Backend\CodeRepository;
use MediaWiki\Extension\CodeReview\Backend\CodeRevision;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use OutputPage;
use RequestContext;
use SpecialPage;
use User;
use Wikimedia\Rdbms\IDatabase;
use Xml;

/**
 * Special:Code/MediaWiki
 */
class CodeRevisionListView extends CodeView {
	public bool $batchForm = false;

	/**
	 * @var string[]
	 */
	protected $filters = [];

	private ?string $mAppliedFilter;

	/**
	 * @param CodeRepository|string $repo
	 */
	public function __construct( $repo ) {
		parent::__construct( $repo );
		global $wgRequest;

		$path = $wgRequest->getVal( 'path' );

		if ( $path != '' ) {
			$this->mPath = self::pathsToArray( $path );
		} else {
			$this->mPath = [];
		}

		$this->mAuthor = $wgRequest->getText( 'author' );
		$this->mStatus = $wgRequest->getText( 'status' );

		if ( $this->mAuthor ) {
			$this->filters[] = wfMessage( 'code-revfilter-cr_author', $this->mAuthor )->text();
		}
		if ( $this->mStatus ) {
			$this->filters[] = wfMessage( 'code-revfilter-cr_status', $this->mStatus )->text();
		}

		if ( count( $this->filters ) ) {
			global $wgLang;
			$this->mAppliedFilter = $wgLang->listToText( $this->filters );
		} else {
			$this->mAppliedFilter = null;
		}
	}

	/**
	 * @param string $path
	 * @return array
	 */
	public static function pathsToArray( $path ) {
		return array_map( [ self::class, 'preparePaths' ], explode( '|', $path ) );
	}

	/**
	 * @param string $path
	 * @return string
	 */
	public static function preparePaths( $path ) {
		$path = trim( $path );
		$path = rtrim( $path, '/' );
		$escPath = htmlspecialchars( $path );
		if ( strlen( $escPath ) && $escPath[0] !== '/' ) {
			// make sure this is a valid path
			$escPath = "/{$escPath}";
		}
		return $escPath;
	}

	/**
	 * @return string
	 */
	public function getPathsAsString() {
		return implode( '|', $this->mPath );
	}

	public function execute() {
		global $wgOut, $wgRequest;

		// Todo inject instead of accessing the global
		$output = $wgOut;

		if ( !$this->mRepo ) {
			$view = new CodeRepoListView();
			$view->execute();
			return;
		}

		$user = RequestContext::getMain()->getUser();

		// Check for batch change requests.
		$editToken = $wgRequest->getVal( 'wpBatchChangeEditToken' );
		$revisions = $wgRequest->getArray( 'wpRevisionSelected' );
		if ( $wgRequest->wasPosted() && count( $revisions )
			&& RequestContext::getMain()->getCsrfTokenSet()->matchToken( $editToken )
		) {
			$this->doBatchChange( $output, $user );
			return;
		}

		// Get the total count across all pages
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );
		$revCount = $this->getRevCount( $dbr );

		$pager = $this->getPager();
		$pathForm = $this->showForm( $pager );

		// Build batch change interface as needed
		$this->batchForm = $user->isAllowed( 'codereview-set-status' ) ||
			$user->isAllowed( 'codereview-add-tag' );

		$navBar = $pager->getNavigationBar();

		$output->addHTML( $pathForm );

		$output->addHTML(
			$navBar .
			'<table><tr><td>' . $pager->getLimitForm() . '</td>'
		);
		if ( $revCount !== -1 ) {
			$output->addHTML(
			'<td>&#160;<strong>' .
				wfMessage( 'code-rev-total' )->numParams( $revCount )->escaped() .
				'</strong></td>'
			);
		}

		$output->addHTML(
			'</tr></table>' .
			Xml::openElement( 'form',
				[ 'action' => $pager->getTitle()->getLocalURL(), 'method' => 'post' ]
			) .
			$pager->getBodyOutput()->getContentHolderText() .
			// $pager->getLimitDropdown() .
			$navBar
		);
		if ( $this->batchForm ) {
			$output->addHTML(
				$this->buildBatchInterface( $pager, $user )
			);
		}

		$output->addHTML( Xml::closeElement( 'form' ) . $pathForm );
	}

	private function doBatchChange( OutputPage $output, User $user ) {
		global $wgRequest;

		$revisions = $wgRequest->getArray( 'wpRevisionSelected' );
		$removeTags = $wgRequest->getVal( 'wpRemoveTag' ) ?? '';
		$addTags = $wgRequest->getVal( 'wpTag' ) ?? '';
		$status = $wgRequest->getVal( 'wpStatus' );

		// Grab data from the DB
		$dbr = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getMaintenanceConnectionRef( DB_REPLICA );
		$revObjects = [];
		$res = $dbr->select(
			'code_rev', '*',
			[ 'cr_id' => $revisions, 'cr_repo_id' => $this->mRepo->getId() ],
			__METHOD__
		);
		foreach ( $res as $row ) {
			$revObjects[] = CodeRevision::newFromRow( $this->mRepo, $row );
		}

		if ( $user->isAllowed( 'codereview-add-tag' ) &&
			$addTags || $removeTags
		) {
			$addTags = array_map( 'trim', explode( ",", $addTags ) );
			$removeTags = array_map( 'trim', explode( ",", $removeTags ) );

			foreach ( $revObjects as $rev ) {
				$rev->changeTags( $addTags, $removeTags, $user );
			}
		}

		if ( $user->isAllowed( 'codereview-set-status' ) &&
				$revObjects && CodeRevision::isValidStatus( $status ) ) {
			foreach ( $revObjects as $rev ) {
				$rev->setStatus( $status, $user );
			}
		}

		// Automatically refresh
		// This way of getting GET parameters is horrible, but effective.
		$fields = $wgRequest->getValues();
		foreach ( array_keys( $fields ) as $key ) {
			if ( str_starts_with( $key, 'wp' ) || $key == 'title' ) {
				unset( $fields[$key] );
			}
		}

		$output->redirect( $this->getPager()->getTitle()->getFullURL( $fields ) );
	}

	/**
	 * @param SvnRevTablePager $pager
	 * @param User $user
	 * @return string
	 */
	protected function buildBatchInterface( $pager, User $user ) {
		$changeFields = [];

		if ( $user->isAllowed( 'codereview-set-status' ) ) {
			$changeFields['code-batch-status'] =
				Xml::tags( 'select', [ 'name' => 'wpStatus' ],
					Xml::tags( 'option',
						[ 'value' => '', 'selected' => 'selected' ], ' '
					) .
					CodeRevisionView::buildStatusList( null, $this )
				);
		}

		if ( $user->isAllowed( 'codereview-add-tag' ) ) {
			$changeFields['code-batch-tags'] = CodeRevisionView::addTagForm( '', '' );
		}

		if ( !count( $changeFields ) ) {
			// nothing to do here
			return '';
		}

		$changeInterface = Xml::openElement( 'fieldset' ) . "\n";
		$changeInterface .= Xml::element( 'legend', null, $pager->msg( 'codereview-batch-title' )->text() ) . "\n";
		// TODO: use OOUI or Codex widgets instead
		$changeInterface .= $this->buildForm(
			$changeFields,
			'codereview-batch-submit'
		) . "\n";
		$changeInterface .= Xml::closeElement( 'fieldset' ) . "\n";

		$changeInterface .= $pager->getHiddenFields();
		$changeInterface .= Html::hidden(
			'wpBatchChangeEditToken',
			RequestContext::getMain()->getCsrfTokenSet()->getToken()
		);

		return $changeInterface;
	}

	/**
	 * Stolen from https://github.com/wikimedia/mediawiki/blob/aaa8af1/includes/Xml/Xml.php#L417 REL1_46
	 */
	private function buildForm( $fields, $submitLabel = null, $submitAttribs = [] ): string {
		$form = "<table><tbody>";

		foreach ( $fields as $labelmsg => $input ) {
			$id = "mw-$labelmsg";
			$form .= Xml::openElement( 'tr', [ 'id' => $id ] );

			// TODO use a <label> here for accessibility purposes - will need
			// to either not use a table to build the form, or find the ID of
			// the input somehow.

			$form .= Xml::tags( 'td', [ 'class' => 'mw-label' ], wfMessage( $labelmsg )->parse() );
			$form .= Xml::tags( 'td', [ 'class' => 'mw-input' ], $input );
			$form .= Xml::closeElement( 'tr' );
		}

		if ( $submitLabel ) {
			$form .= Xml::openElement( 'tr' );
			$form .= Xml::tags( 'td', [], '' );
			$form .= Xml::openElement( 'td', [ 'class' => 'mw-submit' ] )
				. Html::element(
					'input',
					$submitAttribs + [
						'type' => 'submit',
						'value' => wfMessage( $submitLabel )->text(),
					]
				)
				. Xml::closeElement( 'td' );
			$form .= Xml::closeElement( 'tr' );
		}

		$form .= "</tbody></table>";

		return $form;
	}

	/**
	 * @param SvnTablePager $pager
	 *
	 * @return string
	 */
	private function showForm( $pager ) {
		global $wgScript;

		$states = CodeRevision::getPossibleStates();
		$name = $this->mRepo->getName();

		$title = SpecialPage::getTitleFor( 'Code', $name );

		$attribs = [
			'value' => $title->getPrefixedText(),
			'selected' => $this->mStatus == '',
		];
		$options = [ Html::element( 'option', $attribs, $title->getPrefixedText() ) ];

		// Give grep a chance to find the usages:
		// code-status-new, code-status-fixme, code-status-reverted, code-status-resolved,
		// code-status-ok, code-status-deferred, code-status-old
		foreach ( $states as $state ) {
			$title = SpecialPage::getTitleFor( 'Code', $name . "/status/$state" );

			$attribs = [
				'value' => $title->getPrefixedText(),
				'selected' => $this->mStatus == $state
			];

			$options[] = Html::element( 'option', $attribs, $title->getPrefixedText() );
		}

		return '<fieldset><legend>' .
			wfMessage( 'code-pathsearch-legend' )->escaped() . '</legend>' .
			'<table width="100%"><tr><td>' .
			Xml::openElement( 'form', [ 'action' => $wgScript, 'method' => 'get' ] ) .
			Html::label(
				wfMessage( "code-pathsearch-path" )->text(),
				'path',
				[ 'dir' => 'ltr' ]
			) . "\u{00A0}" .
			Html::input(
				'path',
				$this->getPathsAsString(),
				'text',
				[
					'id' => 'path',
					'dir' => 'ltr',
					'size' => 55,
				]

			) . '&#160;' .
			Html::label(
				wfMessage( 'code-pathsearch-filter' )->text(),
				'code-status-filter'
			) .
			'&#160;' .
			Xml::openElement(
				'select',
				[ 'id' => 'code-status-filter', 'name' => 'title' ]
			) .
			"\n" .
			implode( "\n", $options ) .
			"\n" .
			Xml::closeElement( 'select' ) .
			'&#160;' .
			Html::submitButton( wfMessage( 'allpagessubmit' )->text() ) .
			$pager->getHiddenFields( [ 'path', 'title' ] ) .
			Xml::closeElement( 'form' ) .
			'</td></tr></table></fieldset>';
	}

	/**
	 * @return SvnRevTablePager
	 */
	public function getPager() {
		return new SvnRevTablePager( $this );
	}

	/**
	 * Get the total number of revisions for this revision view
	 *
	 * @param IDatabase $dbr
	 * @return int Number of revisions
	 */
	protected function getRevCount( $dbr ) {
		$query = $this->getPager()->getCountQuery();

		$result = $dbr->selectRow( $query['tables'],
			$query['fields'],
			$query['conds'],
			__METHOD__,
			$query['options'],
			$query['join_conds']
		);
		if ( $result ) {
			return intval( $result->rev_count );
		}

		return 0;
	}

	/**
	 * @return CodeRepository
	 */
	public function getRepo() {
		return $this->mRepo;
	}
}
