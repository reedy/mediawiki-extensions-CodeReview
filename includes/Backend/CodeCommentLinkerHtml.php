<?php

namespace MediaWiki\Extension\CodeReview\Backend;

use MediaWiki\Linker\Linker;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class CodeCommentLinkerHtml extends CodeCommentLinker {

	/**
	 * @param string $url
	 * @param string $text
	 * @return string
	 */
	public function makeExternalLink( $url, $text ) {
		global $wgTitle;
		return MediaWikiServices::getInstance()->getLinkRenderer()->makeExternalLink(
			$url,
			$text,
			$wgTitle
		);
	}

	/**
	 * @param Title $title
	 * @param string $text
	 * @return string
	 */
	public function makeInternalLink( $title, $text ) {
		return MediaWikiServices::getInstance()->getLinkRenderer()->makeLink( $title, $text );
	}
}
