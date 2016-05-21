<?php

/**
 * Common config:
 * @property int      $timeout    [optional] a timeout (milliseconds) after page loaded to begin scrap; default 0
 * @property string   $await      [optional] a xpath to check against to begin scrap; default null
 * @property string   $pagination [optional] a xpath to check against to get all pages with current rules set; result will be stored per page; default null
 * @property int      $interval   [optional] an interval (milliseconds) between consecutive page loading; default 0
 * @property string   $userAgent  [optional] a useragent header; default null
 * @property string[] $headers    [optional] custom headers; default null
 * @property string   $isPost     [optional] to use POST method to request page html; default false
 * @property string[] $postData   [optional] post data to use in POST request; default null
 * @property string   $url        an url to start from
 * @property mixed[]  $rules      an array of Rule configs (see below)
 * 
 * Rule config:
 * Each rule can be either xpath (string with leading $), selector (string) or sub-ruleset (array or Rule configs). 
 * Each sub-ruleset may contain context node selector/xpath under key "$" - rules in this ruleset will be scraped related to context node.
 * If xpath rule trailed with ":single" then scrap result will be stored as object, otherwise as array.
 * If selector trailed with ":single" then scrap result will be stored as object, otherwise as array.
 * If selector trailed with ":attr(attrname)" then scrap result will be an attribute's value, otherwise element's text content.
 * "&" selector (without trailing hooks) is equal to xpath ".".
 **/

return [
	'url'   => 'https://github.com/ariya/phantomjs/issues',
	'rules' => [
		// issues by selectors
		'issues' => [
			'$'     => '.table-list-issues > li',
			'url'   => '.issue-title-link:single:attr(href)',
			'title' => '.issue-title-link:single',
		],
		
		// issues by xpath (have to be the same as above)
		'$issues' => [
			'$'     => "$//*[contains(concat(' ', normalize-space(@class), ' '), ' table-list-issues ')]/li",
			'url'   => "$.//*[contains(concat(' ', normalize-space(@class), ' '), ' issue-title-link ')]/@href:single",
			'title' => "$.//*[contains(concat(' ', normalize-space(@class), ' '), ' issue-title-link ')]:single",
		],
	],
	'pagination' => '.pagination a:attr(href)',
	'interval'   => 1000,
];