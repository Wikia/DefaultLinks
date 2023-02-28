<?php

namespace DefaultLinks;

use Config;
use DeferrableUpdate;
use MagicWordFactory;
use MediaWiki\Hook\InternalParseBeforeLinksHook;
use MediaWiki\Hook\ParserBeforeInternalParseHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Page\PageIdentity;
use MediaWiki\Page\PageReference;
use MediaWiki\Revision\RenderedRevision;
use MediaWiki\Storage\Hook\RevisionDataUpdatesHook;
use Parser;
use ParserOptions;
use PPFrame;
use ReflectionProperty;
use StripState;
use Title;
use TitleFormatter;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Manage parser hooks to support the {{DEFAULTLINK:}} parser function.
 */
class Hooks implements
	ParserFirstCallInitHook,
	ParserBeforeInternalParseHook,
	InternalParseBeforeLinksHook,
	RevisionDataUpdatesHook
{
	/**
	 * Page ID/Title or 'Page ID#fragment' => link text (retrieval cache)
	 * @var string[]|false[]
	 */
	private $knownFormatting = [];
	/**
	 * ParserOptions objects for which default links are disabled
	 * @var ParserOptions[]
	 */
	private $supressedOptions = [];

	/**
	 * Recursion guard used while sanitizing default link format values.
	 * @var bool
	 */
	private bool $recursionGuard = false;

	public function __construct(
		private Config $config,
		private ILoadBalancer $dbLoadBalancer,
		private TitleFormatter $titleFormatter,
		private MagicWordFactory $magicWordFactory
	) {
	}

	/**
	 * Add parser function hooks to the new parser, create an instance of this object
	 * if one does not already exist.
	 * @param Parser $parser
	 */
	public function onParserFirstCallInit( $parser ): void {
		$parser->setFunctionHook(
			'defaultlink',
			[ $this, 'linkParserFunction' ],
			SFH_NO_HASH | SFH_OBJECT_ARGS
		);
		$parser->setHook( 'nodefaultlinks', [ $this, 'noLinksTag' ] );
	}

	/**
	 * Returns whether the article is in a namespace that is allowed to define
	 * incoming link formatting.
	 * @param PageReference $title
	 * @return bool
	 */
	private function nsHasFormattedLinks( PageReference $title ) {
		// It's not possible to inject configuration overrides into parser tests in time for
		// article fixture initialization, so hardcode the allowed set of namespaces there.
		if ( defined( 'MW_PARSER_TEST' ) ) {
			return $title->getNamespace() === NS_MAIN;
		}

		return in_array( $title->getNamespace(), $this->config->get( 'DFEnabledNamespaces' ) );
	}

	/**
	 * Serialize the defaultlinksec property before updating it in the database.
	 * @param Title $title
	 * @param RenderedRevision $renderedRevision
	 * @param DeferrableUpdate[] &$updates
	 */
	public function onRevisionDataUpdates( $title, $renderedRevision, &$updates ): void {
		$parserOutput = $renderedRevision->getRevisionParserOutput();
		$defaultLinkSec = $parserOutput->getExtensionData( 'defaultlinksec' );
		if ( $defaultLinkSec !== null ) {
			$s = '';
			foreach ( $defaultLinkSec as $k => $v ) {
				$s .= ( $s == '' ? '' : "\n" ) . $k . "\n" . $v;
			}
			$parserOutput->setPageProperty( 'defaultlinksec', $s );
			$parserOutput->setExtensionData( 'defaultlinksec', null );
		}
	}

	/**
	 * Replaces default links with their expansions as necessary.
	 * @param Parser $parser
	 * @param string &$text
	 * @param StripState $stripState
	 * @return bool
	 */
	public function onInternalParseBeforeLinks( $parser, &$text, $stripState ): bool {
		// Avoid infinite recursion if called from within recursiveTagParse()
		// when sanitizing default link format values for output.
		if ( $this->recursionGuard ) {
			return true;
		}

		$page = $parser->getPage();
		$this_page_name = $page !== null ? $this->titleFormatter->getPrefixedText( $page ) : '';
		$this_page_id = $page instanceof PageIdentity ? $page->getId() : 0;
		$this_page_link = $parser->getOutput()->getPageProperty( 'defaultlink' );
		$this_page_slinks = $parser->getOutput()->getExtensionData( 'defaultlinksec' ) ?? [];

		if ( $this->magicWordFactory->get( 'nodefaultlink' )->matchAndRemove( $text ) ) {
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
			$whole = $linkMatches[0][$key];
			if ( isset( $lock[$whole] ) ) {
				continue;
			}
			if ( strpos( $target, '%' ) !== false ) {
				$target = str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], urldecode( $target ) );
			}
			$lock[$whole] = true;
			$title = Title::newFromText( $target );
			if ( !is_object( $title ) || !$this->nsHasFormattedLinks( $title ) ) {
				continue;
			}
			$page_id = $title->getArticleID();
			if ( $title->getPrefixedText() == '' ) {
				$page_id = $this_page_id;
			}

			$fragment = strtolower( $title->getFragment() );
			if ( $fragment != '' ) {
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
					// Looked up (did not exist), or self-link (while not in section preview),
					// so properties are stale.
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
			$db = $this->dbLoadBalancer->getConnection( DB_REPLICA );
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

			// SECURITY: Run user-provided default link formats through recursiveTagParse()
			// to clean up any unsafe HTML such as script tags.
			// At this parsing stage, the parser will already have performed this cleanup on the
			// main article wikitext, so it won't automatically take care of this for us.
			$this->recursionGuard = true;
			$replace = array_map(
				[ $parser, 'recursiveTagParse' ],
				$replace
			);
			$this->recursionGuard = false;

			$text2 = preg_replace( $find, $replace, $text );
			$size = strlen( $text2 ) - strlen( $text );
			if ( $size > 0 ) {
				// There's no extension API in the Parser for tracking included content size,
				// so we need to manipulate the raw property.
				$mIncludeSizesProperty = new ReflectionProperty( Parser::class, 'mIncludeSizes' );
				$mIncludeSizesProperty->setAccessible( true );
				$mIncludeSizes = $mIncludeSizesProperty->getValue( $parser );

				if ( $mIncludeSizes['post-expand'] + $size > $parser->getOptions()->getMaxIncludeSize() ) {
					$parser->limitationWarn( 'post-expand-template-inclusion' );

					return true;
				} else {
					$mIncludeSizes['post-expand'] += $size;
					$mIncludeSizesProperty->setValue( $parser, $mIncludeSizes );
				}
			}
			$text = $text2;
		}

		return true;
	}

	/**
	 * Captures use of the {{DEFAULTLINK:link|for page|silent}} magic word on the page.
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @param array $args
	 * @return string
	 */
	public function linkParserFunction( Parser $parser, PPFrame $frame, array $args ) {
		$title = $parser->getPage();
		$silent =
			isset( $args[2] ) &&
			$this->magicWordFactory->get( 'silent' )->match( $frame->expand( $args[2] ) );
		$hasLinks = $title !== null && $this->nsHasFormattedLinks( $title );
		if ( $silent && !$hasLinks ) {
			return '';
		}

		$thisTitle = $title !== null ? $this->titleFormatter->getPrefixedText( $title ) : '';
		$forPage = isset( $args[1] ) ? trim( $frame->expand( $args[1] ) ) : '';
		$fragment = '';
		if ( strpos( $forPage, '%' ) !== false ) {
			$forPage = str_replace( [ '<', '>' ], [ '&lt;', '&gt;' ], urldecode( $forPage ) );
		}

		if ( $forPage != '' ) {
			// Check whether this tag is meant for this page
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
		if ( !preg_match( '/\[\[.*\]\]/s', $link ) ) {
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
					$oldLink = $parser->getOutput()->getPageProperty( 'defaultlink' );
					$parser->getOutput()->setPageProperty( 'defaultlink', $link );
					if ( $oldLink !== null && trim( $oldLink ) != trim( $link ) ) {
						return '<span class="error">' .
							   wfMessage( 'duplicate-defaultlink', $oldLink, $link )->text() . '</span>';
					}
				} else {
					$oldLinks = $parser->getOutput()->getExtensionData( 'defaultlinksec' ) ?? [];
					$oldLinks[strtolower( $fragment )] = $link;
					$parser->getOutput()->setExtensionData( 'defaultlinksec', $oldLinks );
				}
				break;
			}
		}

		return '';
	}

	/**
	 * Tag hook: disable default link functionality within
	 * @param string $text
	 * @param array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string
	 */
	public function noLinksTag( $text, $args, Parser $parser, PPFrame $frame ) {
		$oldSup = $this->supressedOptions;
		$this->supressedOptions[] = $parser->getOptions();
		$ret = $parser->recursiveTagParse( $text, $frame );
		$this->supressedOptions = $oldSup;

		return $ret;
	}

	/**
	 * Block default links on the entire page using a magic word.
	 * @param Parser $parser
	 * @param string &$text
	 * @param StripState $stripState
	 * @return bool
	 */
	public function onParserBeforeInternalParse( $parser, &$text, $stripState ) {
		$pure = preg_replace( '#<nowiki>.*?</nowiki>#i', '', $text );
		if ( $this->magicWordFactory->get( 'nodefaultlink' )->match( $pure ) ) {
			$this->supressedOptions[] = $parser->getOptions();
		}

		return true;
	}
}
