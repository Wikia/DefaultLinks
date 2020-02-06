<?php
/**
 * DefaultLinks
 * DefaultLinks Extension
 *
 * @package		DefaultLinks
 * @link		https://help.gamepedia.com/Extension:DefaultLinks
 *
 **/
if (function_exists('wfLoadExtension')) {
	wfLoadExtension('DefaultLinks');
	// Keep i18n globals so mergeMessageFileList.php doesn't break
	$wgMessagesDirs['DefaultLinks'] = __DIR__ . '/i18n';
	wfWarn(
		'Deprecated PHP entry point used for DefaultLinks extension. Please use wfLoadExtension instead, ' .
		'see https://www.mediawiki.org/wiki/Extension_registration for more details.'
	);
	return;
} else {
	die( 'This version of the DefaultLinks extension requires MediaWiki 1.25+' );
}
