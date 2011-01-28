<?php

// Special:Code/MediaWiki/author
class CodeAuthorListView extends CodeView {
	function __construct( $repoName ) {
		parent::__construct();
		$this->repo = CodeRepository::newFromName( $repoName );
	}

	function execute() {
		global $wgOut, $wgLang;
		$authors = $this->repo->getAuthorList();
		$repo = $this->repo->getName();
		$text = wfMsg( 'code-authors-text' ) . "\n\n";
		$text .= '<strong>' . wfMsg( 'code-author-total', $wgLang->formatNum( $this->repo->getAuthorCount() ) )  . "</strong>\n";

		$wgOut->addWikiText( $text );

		$wgOut->addHTML( '<table class="TablePager">'
				. '<tr><th>' . wfMsgHtml( 'code-field-author' )
				. '</th><th>' . wfMsgHtml( 'code-author-lastcommit' ) . '</th></tr>' );

		foreach ( $authors as $committer ) {
			if ( $committer ) {
				$wgOut->addHTML( "<tr><td>" );
				$author = $committer["author"];
				$text = "[[Special:Code/$repo/author/$author|$author]]";
				$user = $this->repo->authorWikiUser( $author );
				if ( $user ) {
					$title = htmlspecialchars( $user->getUserPage()->getPrefixedText() );
					$name = htmlspecialchars( $user->getName() );
					$text .= " ([[$title|$name]])";
				}
				$wgOut->addWikiText( $text );
				$wgOut->addHTML( "</td><td>{$wgLang->timeanddate( $committer['lastcommit'], true )}</td></tr>" );
			}
		}

		$wgOut->addHTML( '</table>' );
	}
}
