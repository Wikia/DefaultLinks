<?php

namespace DefaultLinks;

use LinksUpdate;
use MagicWord;
use MediaWiki\MediaWikiServices;
use Parser;
use Title;

class Hooks {
	private $knownFormatting = []; /* Page ID/Title or 'Page ID#fragment' => link text (retrieval cache) */
	private $supressedOptions = []; /* ParserOptions objects for which default links are disabled */

	/* Add parser function hooks to the new parser, create an instance of this object
	 * if one does not already exist.
	 */
	public static function onParserFirstCallInit( Parser &$parser ) {
		static $instance = null;
		if ( $instance == null ) {
			global $wgHooks;
			$instance = new Hooks;
			$wgHooks['InternalParseBeforeSanitize'][] = $instance;
			$wgHooks['ParserBeforeInternalParse'][] = $instance;
		}
		$parser->setFunctionHook(
			'defaultlink',
			[ $instance, 'linkParserFunction' ],
			SFH_NO_HASH | SFH_OBJECT_ARGS
		);
		$parser->setHook( 'nodefaultlinks', [ $instance, 'noLinksTag' ] );

		return true;
	}

	/* Delete stored properties for a given page ID.
	 */
	private static function deleteProps( $page_id ) {
		wfGetDB( DB_PRIMARY )->delete(
			'page_props',
			[ 'pp_page' => $page_id, 'pp_propname' => [ 'defaultlink', 'defaultlinksec' ] ],
			__METHOD__
		);
	}

	/* Returns whether the article is in a namespace that is allowed to define
	 * incoming link formatting.
	 */
	private static function nsHasFormattedLinks( Title $title ) {
		global $wgDFEnabledNamespaces;

		return in_array( $title->getNamespace(), $wgDFEnabledNamespaces );
	}

	/* Serialize the defaultlinksec property before updating it in database.
	 */
	public static function onLinksUpdateConstructed( LinksUpdate &$lu ) {
		if ( isset( $lu->mProperties ) && isset( $lu->mProperties['defaultlinksec'] ) ) {
			$s = '';
			foreach ( $lu->mProperties['defaultlinksec'] as $k => $v ) {
				$s .= ( $s == '' ? '' : "\n" ) . $k . "\n" . $v;
			}
			$lu->mProperties['defaultlinksec'] = $s;
		}

		return true;
	}

	/* Delete link properties when deleting an article.
	 */
	public static function onPageDeleteComplete( &$article, &$user, $reason, $id ) {
		self::deleteProps( $id );

		return true;
	}

	/* Replaces default links with their expansions as necessary.
	 */
	public function onInternalParseBeforeSanitize( &$parser, &$text, &$stripState ) {
		$this_page_name = $parser->getTitle()->getPrefixedText();
		$this_page_id = $parser->getTitle()->getArticleID();
		$this_page_link = $parser->getOutput()->getProperty( 'defaultlink' );
		$this_page_slinks = $parser->getOutput()->getProperty( 'defaultlinksec' );

		if ( self::getMagicWord( 'nodefaultlink' )->matchAndRemove( $text ) ) {
			$this->supressedOptions[] = $parser->getOptions();

			return true;
		} elseif ( in_array( $parser->getOptions(), $this->supressedOptions ) ) {
			return true;
		}

		preg_match_all( '/\[\[([^:][^|\]]*[^|\s\]])\s*\]\](?![[:alpha:]])/', $text, $linkMatches );
		$lookup = [];
		$lock = [];
		$lookupReplace = [];
		$find = [];
		$replace = [];

		foreach ( $linkMatches[1] as $key => $target ) {
			if ( isset( $lock[$whole = $linkMatches[0][$key]] ) ) {
				continue;
			}
			if ( strpos( $target, '%' ) !== false ) {
				$target = str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], urldecode( $target ) );
			}
			$lock[$whole] = true;
			$title = Title::newFromText( $target );
			if ( !is_object( $title ) || !self::nsHasFormattedLinks( $title ) ) {
				continue;
			}
			$page_id = $title->getArticleID();
			if ( $title->getPrefixedText() == '' ) {
				$page_id = $this_page_id;
			}

			if ( ( $fragment = strtolower( $title->getFragment() ) ) != '' ) {
				$hkey = $page_id . '#' . $fragment;
				if ( $page_id == $this_page_id && $this_page_slinks &&
					 isset( $this_page_slinks[$fragment] ) ) {
					$this->knownFormatting[$hkey] = $this_page_slinks[$fragment];
				}
				if ( isset( $this->knownFormatting[$hkey] ) ) {
					$find[] = $whole;
					$replace[] = $this->knownFormatting[$hkey];
				} elseif ( isset( $this->knownFormatting[$page_id] ) ||
						   ( $page_id == $this_page_id &&
							 !$parser->getOptions()->getIsSectionPreview() ) ) {
					// Looked up (did not exist), or self-link (while not in section preview) -- so properties are stale.
				} else {
					$lookup[$page_id] = true;
					$lookupReplace[$whole] = $hkey;
				}
			} elseif ( $title->getPrefixedText() == $this_page_name && $this_page_link ) {
				$find[] = $whole;
				$replace[] = $this_page_link;
			} elseif ( isset( $this->knownFormatting[$page_id] ) ) {
				if ( $this->knownFormatting[$page_id] === false ) {
					continue;
				}
				$find[] = $whole;
				$replace[] = $this->knownFormatting[$page_id];
			} elseif ( $page_id != $this_page_id || $parser->getOptions()->getIsSectionPreview() ) {
				$lookup[$page_id] = true;
				$lookupReplace[$whole] = $page_id;
			}
		}

		if ( count( $lookup ) > 0 ) {
			$db = wfGetDB( DB_REPLICA );
			$fmtRes = $db->select(
				[ 'page_props' ],
				[
					'pp_page',
					'pp_propname',
					'pp_value',
				],
				[
					'pp_page' => array_keys( $lookup ),
					'pp_propname' => [
						'defaultlink',
						'defaultlinksec',
					],
				],
				__METHOD__
			);
			foreach ( $fmtRes as $row ) {
				if ( $row->pp_propname == 'defaultlinksec' ) {
					$links = explode( "\n", $row->pp_value );
					for ( $i = 0, $c = count( $links ) - 1; $i < $c; $i += 2 ) {
						$this->knownFormatting[$row->pp_page . '#' .
											   strtolower( trim( $links[$i] ) )] = $links[$i + 1];
					}
				} else {
					$this->knownFormatting[intval( $row->pp_page )] = $row->pp_value;
				}
			}
			foreach ( $lookupReplace as $whole => $key ) {
				$known = isset( $this->knownFormatting[$key] );
				if ( $known && $this->knownFormatting[$key] !== false ) {
					$find[] = $whole;
					$replace[] = $this->knownFormatting[$key];
				} elseif ( !$known && is_int( $key ) ) {
					$this->knownFormatting[$key] = false;
				}
			}
		}

		if ( count( $find ) > 0 ) {
			foreach ( $find as $k => $v ) {
				$find[$k] = '#' . preg_quote( $v, '#' ) . '(?![[:alpha:]])#';
			}
			$text2 = preg_replace( $find, $replace, $text );
			$size = strlen( $text2 ) - strlen( $text );
			if ( $size > 0 && !$parser->incrementIncludeSize( 'post-expand', $size ) ) {
				$parser->limitationWarn( 'post-expand-template-inclusion' );

				return true;
			}
			$text = $text2;
		}

		return true;
	}

	/* Captures use of the {{DEFAULTLINK:link|for page|silent}} magic word on the page.
	 */
	public function linkParserFunction( &$parser, $frame, $args ) {
		$title = $parser->getTitle();
		$silent =
			isset( $args[2] ) &&
			self::getMagicWord( 'silent' )->match( $frame->expand( $args[2] ) );
		$hasLinks = self::nsHasFormattedLinks( $title );
		if ( $silent && !$hasLinks ) {
			return '';
		}

		$thisTitle = $title->getPrefixedText();
		$forPage = isset( $args[1] ) ? trim( $frame->expand( $args[1] ) ) : '';
		$fragment = '';
		if ( strpos( $forPage, '%' ) !== false ) {
			$forPage = str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], urldecode( $forPage ) );
		}

		if ( $forPage != '' ) { // Check whether this tag is meant for this page
			$forTitle = Title::newFromText( $forPage );
			if ( !is_object( $forTitle ) ) {
				return $silent
					? ''
					: '<span class="error">' .
					  wfMessage( 'defaultlink-target-page-invalid' )->text() . '</span>';
			}
			if ( $forTitle->getPrefixedText() != '' &&
				 $forTitle->getPrefixedText() != $thisTitle ) {
				return '';
			}
			$forPage = $forTitle->getPrefixedText();
			$fragment = str_replace( "\n", '', $forTitle->getFragment() );
		}
		$link = isset( $args[0] ) ? trim( $frame->expand( $args[0] ) ) : '';
		if ( !is_string( $link ) || !preg_match( '/\[\[.*\]\]/s', $link ) ) {
			return $silent ? ''
				: '<span class="error">' . wfMessage( 'defaultlink-invalid-link' )->text() .
				  '</span>';
		}

		$link = str_replace( "\n", '', $link );
		preg_match_all( '/\[\[\s*([^|\]]*)(.*?)\]\]/s', $link, $linkMatches );

		foreach ( $linkMatches[1] as $key => $target ) {
			$tarTitle = Title::newFromText( $target );
			if ( !is_object( $tarTitle ) ) {
				continue;
			}
			$targetTitle = $tarTitle->getPrefixedText();
			if ( $tarTitle->getNamespace() == NS_FILE && $target[0] != ':' ) {
				if ( preg_match(
					'/\|\s*link=\s*([^|\\]]+)/',
					$linkMatches[2][$key],
					$fileLinkMatch
				) ) {
					$fileLinkTitle = Title::newFromText( $fileLinkMatch[1] );
					$targetTitle =
						is_object( $fileLinkTitle ) ? $fileLinkTitle->getPrefixedText()
							: $targetTitle;
				}
			}
			if ( $thisTitle == $targetTitle ) {
				if ( !$hasLinks ) {
					return '<span class="error">' .
						   wfMessage( 'defaultlink-disallowed-namespace' )->text() . '</span>';
				}
				if ( $fragment == '' ) {
					$oldLink = $parser->getOutput()->getProperty( 'defaultlink' );
					$parser->getOutput()->setProperty( 'defaultlink', $link );
					if ( $oldLink !== false && trim( $oldLink ) != trim( $link ) ) {
						return '<span class="error">' .
							   wfMessage( 'duplicate-defaultlink', $oldLink, $link )->text() . '</span>';
					}
				} else {
					$oldLinks = $parser->getOutput()->getProperty( 'defaultlinksec' );
					if ( $oldLinks == false ) {
						$oldLinks = [];
					}
					$oldLinks[strtolower( $fragment )] = $link;
					$parser->getOutput()->setProperty( 'defaultlinksec', $oldLinks );
				}
				break;
			}
		}

		return '';
	}

	/* Tag hook: disable default link functionality within
	 */
	public function noLinksTag( $text, $args, $parser, $frame ) {
		$oldSup = $this->supressedOptions;
		$this->supressedOptions[] = $parser->getOptions();
		$ret = $parser->recursiveTagParse( $text, $frame );
		$this->supressedOptions = $oldSup;

		return $ret;
	}

	/* Block default links on the entire page using a magic word.
	 */
	public function onParserBeforeInternalParse( &$parser, &$text, &$stripState ) {
		$pure = preg_replace( '#<nowiki>.*?</nowiki>#i', '', $text );
		if ( self::getMagicWord( 'nodefaultlink' )->match( $pure ) ) {
			$this->supressedOptions[] = $parser->getOptions();
		}

		return true;
	}

	private static function getMagicWord( string $id ): MagicWord {
		return MediaWikiServices::getInstance()->getMagicWordFactory()->get( $id );
	}
}
