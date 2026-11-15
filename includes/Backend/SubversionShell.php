<?php

namespace MediaWiki\Extension\CodeReview\Backend;

use DOMDocument;
use Exception;
use MediaWiki\Shell\Shell;
use Wikimedia\Timestamp\TimestampFormat;

/**
 * Using the thingy-bobber
 */
class SubversionShell extends SubversionAdaptor {
	private const MIN_MEMORY = 204800;

	/** @inheritDoc */
	public function __construct( $repo ) {
		parent::__construct( $repo );
		global $wgMaxShellMemory;
		if ( $wgMaxShellMemory < self::MIN_MEMORY ) {
			$wgMaxShellMemory = self::MIN_MEMORY;
			wfDebug( __METHOD__ . " raised wgMaxShellMemory to $wgMaxShellMemory\n" );
		}
	}

	public function canConnect() {
		$command = sprintf(
			'svn info %s %s',
			$this->getExtraArgs(),
			Shell::escape( $this->mRepoPath )
		);

		$result = Shell::command( $command )->execute()->getStdout();
		if ( $result == '' ) {
			return false;
		} elseif ( str_contains( $result, 'No repository found' ) ) {
			return false;
		} else {
			return true;
		}
	}

	/** @inheritDoc */
	public function getFile( $path, $rev = null ) {
		if ( $rev ) {
			$path .= "@$rev";
		}
		$command = sprintf(
			'svn cat %s %s',
			$this->getExtraArgs(),
			Shell::escape( $this->mRepoPath . $path )
		);

		return Shell::command( $command )->execute()->getStdout();
	}

	/** @inheritDoc */
	public function getDiff( $path, $rev1, $rev2 ) {
		$command = sprintf(
			'svn diff -r%d:%d %s %s',
			intval( $rev1 ),
			intval( $rev2 ),
			$this->getExtraArgs(),
			Shell::escape( $this->mRepoPath . $path )
		);

		return Shell::command( $command )->execute()->getStdout();
	}

	/** @inheritDoc */
	public function getLog( $path, $startRev = null, $endRev = null ) {
		$lang = wfIsWindows() ? '' : 'LC_ALL=en_US.utf-8 ';
		$command = sprintf(
			"{$lang}svn log -v -r%s:%s %s %s",
			Shell::escape( $this->_rev( $startRev, 'BASE' ) ),
			Shell::escape( $this->_rev( $endRev, 'HEAD' ) ),
			$this->getExtraArgs(),
			Shell::escape( $this->mRepoPath . $path )
		);

		$lines = explode( "\n", Shell::command( $command )->execute()->getStdout() );
		$out = [];

		$divider = str_repeat( '-', 72 );
		$formats = [
			'rev' => '/^r(\d+)$/',
			'author' => '/^(.*)$/',
			// account for '(no date)'
			'date' => '/^(?:(.*?) )?\(.*\)$/',
			'lines' => '/^(\d+) lines?$/',
		];
		$state = "start";
		foreach ( $lines as $line ) {
			$line = rtrim( $line );

			switch ( $state ) {
				case 'start':
					if ( $line == $divider ) {
						$state = 'revdata';
						break;
					} else {
						return $out;
						# throw new Exception( "Unexpected start line: $line" );
					}
					// Fall through
				case 'revdata':
					if ( $line == '' ) {
						$state = 'done';
						break;
					}
					$data = [];
					$bits = explode( ' | ', $line );
					$i = 0;
					foreach ( $formats as $key => $regex ) {
						$text = $bits[$i++];
						$matches = [];
						if ( preg_match( $regex, $text, $matches ) ) {
							$data[$key] = $matches[1];
						} else {
							throw new Exception(
								"Unexpected format for $key in '$text'"
							);
						}
					}
					$data['msg'] = '';
					$data['paths'] = [];
					$state = 'changedpaths';
					break;
				case 'changedpaths':
					if ( $line == 'Changed paths:' ) {
						// broken when svn messages are not in English
						$state = 'path';
					} elseif ( $line == '' ) {
						// No changed paths?
						$state = 'msg';
					} else {
						throw new Exception(
							"Expected 'Changed paths:' or '', got '$line'" );
					}
					break;
				case 'path':
					if ( $line == '' ) {
						// Out of paths. Move on to the message...
						$state = 'msg';
					} else {
						$matches = [];
						if ( preg_match( '/^   (.) (.*)$/', $line, $matches ) ) {
							// @phan-suppress-next-line PhanUndeclaredVariableDim
							$data['paths'][] = [
								'action' => $matches[1],
								'path' => $matches[2]
							];
						}
					}
					break;
				case 'msg':
					$data['msg'] .= $line;
					// phpcs:ignore Generic.Files.LineLength.TooLong
					// @phan-suppress-next-line PhanTypeArraySuspiciousNull, PhanTypeInvalidUnaryOperandIncOrDec, PhanUndeclaredVariable
					if ( --$data['lines'] ) {
						$data['msg'] .= "\n";
					} else {
						unset( $data['lines'] );
						// @phan-suppress-next-line PhanUndeclaredVariable
						$out[] = $data;
						$state = 'start';
					}
					break;
				case 'done':
					throw new Exception( "Unexpected input after end: $line" );
				default:
					throw new Exception( "Invalid state '$state'" );
			}
		}

		return $out;
	}

	/** @inheritDoc */
	public function getDirList( $path, $rev = null ) {
		$command = sprintf(
			'svn list --xml -r%s %s %s',
			Shell::escape( $this->_rev( $rev, 'HEAD' ) ),
			$this->getExtraArgs(),
			Shell::escape( $this->mRepoPath . $path )
		);
		$document = new DOMDocument();

		$listXml = Shell::command( $command )->execute()->getStdout();
		if ( !$listXml || !$document->loadXML( $listXml ) ) {
			// svn list --xml returns invalid XML if the file does not exist
			// FIXME: report bug upstream
			return false;
		}

		$entries = $document->getElementsByTagName( 'entry' );
		$result = [];
		foreach ( $entries as $entry ) {
			$item = [];
			$item['type'] = $entry->getAttribute( 'kind' );
			foreach ( $entry->childNodes as $child ) {
				switch ( $child->nodeName ) {
					case 'name':
						$item['name'] = $child->textContent;
						break;
					case 'size':
						$item['size'] = intval( $child->textContent );
						break;
					case 'commit':
						$item['created_rev'] = intval( $child->getAttribute( 'revision' ) );
						foreach ( $child->childNodes as $commitEntry ) {
							switch ( $commitEntry->nodeName ) {
								case 'author':
									$item['last_author'] = $commitEntry->textContent;
									break;
								case 'date':
									$item['time_t'] = wfTimestamp(
										TimestampFormat::UNIX->value,
										$commitEntry->textContent
									);
									break;
							}
						}
						break;
				}
			}
			$result[] = $item;
		}
		return $result;
	}

	/**
	 * Returns a string of extra arguments to be passed into the shell commands
	 * @return string
	 */
	private function getExtraArgs() {
		global $wgSubversionOptions, $wgSubversionUser, $wgSubversionPassword;
		$args = $wgSubversionOptions;
		if ( $wgSubversionUser ) {
			$args .= ' --username ' . Shell::escape( $wgSubversionUser )
				. ' --password ' . Shell::escape( $wgSubversionPassword );
		}
		return $args;
	}
}
