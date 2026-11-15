<?php

namespace MediaWiki\Extension\CodeReview\UI;

use MediaWiki\Extension\CodeReview\Backend\CodeRepository;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Repository administration
 */
class SpecialRepoAdmin extends SpecialPage {
	public function __construct() {
		parent::__construct( 'RepoAdmin' );
	}

	/** @inheritDoc */
	public function getRestriction(): string {
		return 'repoadmin';
	}

	public function doesWrites() {
		return true;
	}

	/**
	 * Return an array of subpages that this special page will accept.
	 *
	 * @return string[] subpages
	 */
	public function getSubpagesForPrefixSearch() {
		$repos = CodeRepository::getRepoList();
		if ( count( $repos ) ) {
			$retVal = [];
			foreach ( $repos as $repo ) {
				$retVal[] = $repo->getName();
			}
			sort( $retVal );
			return $retVal;
		}
		return [];
	}

	/**
	 * @param string $subPage
	 */
	public function execute( $subPage ) {
		$this->setHeaders();

		$this->checkPermissions();

		$repo = $this->getRequest()->getVal( 'repo', $subPage );
		if ( $repo == '' ) {
			$view = new RepoAdminListView( $this->getPageTitle() );
		} else {
			$view = new RepoAdminRepoView( $this->getPageTitle( $repo ), $repo, $this->getUser() );
		}
		$view->execute();
	}

	/** @inheritDoc */
	protected function getGroupName() {
		return 'developer';
	}
}
