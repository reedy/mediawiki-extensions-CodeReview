<?php

namespace MediaWiki\Extension\CodeReview\UI;

use MediaWiki\Extension\CodeReview\Backend\CodeRepository;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Output\OutputPage;
use MediaWiki\Title\Title;

/**
 * View for viewing all of the repositories
 */
class RepoAdminListView {
	/**
	 * Reference to Special:RepoAdmin
	 * @var Title
	 */
	private $title;

	/**
	 * @param Title $t Title object referring to Special:RepoAdmin
	 */
	public function __construct( Title $t ) {
		$this->title = $t;
	}

	/**
	 * Get "create new repo" form
	 */
	private function getForm( OutputPage $output ) {
		global $wgScript;

		$formDescriptor = [
			'repoadmin-label' => [
				'type' => 'text',
				'name' => 'repo',
				'label-message' => 'repoadmin-new-label',
				'id' => 'repo'
			]
		];

		$htmlForm = HTMLForm::factory( 'ooui', $formDescriptor, $output->getContext() );
		$htmlForm
			->setMethod( 'get' )
			->setAction( $wgScript )
			->setTitle( $this->title )
			->setSubmitTextMsg( 'repoadmin-new-button' )
			->setWrapperLegendMsg( 'repoadmin-new-legend' )
			->prepareForm()
			->displayForm( false );
	}

	public function execute() {
		global $wgOut;

		// Todo inject instead of accessing the global
		$output = $wgOut;

		$this->getForm( $output );
		$repos = CodeRepository::getRepoList();
		if ( !count( $repos ) ) {
			return;
		}
		$text = '';
		foreach ( $repos as $repo ) {
			$name = $repo->getName();
			$text .= "* [[Special:RepoAdmin/$name|$name]]\n";
		}
		$output->addWikiTextAsInterface( $text );
	}
}
