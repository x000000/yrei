<?php

const ARG_OUTPUT  = '--o';
const ARG_INPUT   = '--i';
const ARG_PHANTOM = '--p';
const ARG_DEBUG   = '--d';

$_SCRIPT_ARGS = [
	ARG_INPUT   => null,
	ARG_OUTPUT  => null,
	ARG_PHANTOM => null,
	ARG_DEBUG   => false,
];

function isArgValue($value) {
	return null !== $value && strpos($value, '--') !== 0;
}

$args = $_SERVER['argv'];
for ($i = 1, $l = count($args); $i < $l; $i++) {
	$key = $args[$i];
	
	if (!array_key_exists($key, $_SCRIPT_ARGS)) {
		continue;
	}

	switch ($key) {
		// single string value
		case ARG_INPUT:
		case ARG_OUTPUT:
		case ARG_PHANTOM:
			if (isArgValue($value = @$args[$i + 1])) {
				$_SCRIPT_ARGS[$key] = $value;
				$i++;
			}
			break;
		
		// one way single bool value -> true
		case ARG_DEBUG:
			$_SCRIPT_ARGS[$key] = true;
			break;

		// toggleable single bool value
		case ARG_DEBUG:
			if (isArgValue($value = @$args[$i + 1])) {
				if (preg_match('#^1|y|yes$#i', $value)) {
					$_SCRIPT_ARGS[$key] = true;
					$i++;
				}
				elseif (preg_match('#^0|n|no$#i', $value)) {
					$_SCRIPT_ARGS[$key] = false;
					$i++;
				}
			}
			break;
	}
}

if ($_SCRIPT_ARGS[ARG_INPUT]) {
	$config = require( $_SCRIPT_ARGS[ARG_INPUT] );
} else {
	die('Error: no input supplied');
}

if (empty($config['url'])) {
	die('Error: no url supplied');
}
if (empty($config['rules'])) {
	die('Error: no rules supplied');
}

function getDir($path) {
	$path .= '';
	if ($path) {
		$path = realpath($path);
		if (substr($path, -1) != DIRECTORY_SEPARATOR) {
			$path .= DIRECTORY_SEPARATOR;
		}
	}
	return $path;
}

function getPath($path) {
	$info = pathinfo($path);
	$path = getDir(realpath($info['dirname']));
	
	if ($info['basename'] !== '.') {
		$path .= $info['basename'];
	}
	
	return $path;
}

function query($config) {
	global $_SCRIPT_ARGS;

	$debugStop = $_SCRIPT_ARGS[ARG_DEBUG] ? 'debugger;' : '';
	$config    = json_encode($config, JSON_UNESCAPED_UNICODE);
	$file      = tempnam('/tmp', 'phantom');
	$script    = <<< EOL

"use strict";

var system   = require('system')
  , webpage  = require('webpage')
  , config   = $config
  , result   = {}
  , page;

if (config.isPost) {
	var data = [];
	for (var key in config.postData || []) {
		data.push( encodeURI(key) + '=' + encodeURI(config.postData[key]) );
	}
	config.postData = data.join('&');
}

function die() {
	console.log(JSON.stringify(config.pagination ? result : result[ config.url ]));
	phantom.exit();
}

function queryNext() {
	page.close();

	for (var i in result) {
		var url = i;
		if (result[i] === null) {
			if ((config.interval || 0) > 0) {
				setTimeout(function() { queryPage(url); }, config.interval);
			} else {
				queryPage(url);
			}
			return;
		}
	}

	die();
}

var counter = 0;
function queryPage(url) {
	console.log('-> Loading ' + url + ' (' + ++counter + '/' + Object.keys(result).length + ')');
	page = webpage.create();

	if (config.headers) {
		page.customHeaders = config.headers;
	}
	if (config.userAgent) {
		page.settings.userAgent = config.userAgent;
	}

	function onLoad(status) {
		if (status === 'success') {
			$debugStop

			var scrapResult = page.evaluate(function(config) {
				$debugStop

				function sleep(time) {
					var await = true;
					setTimeout(function() { await = false; }, time);
					while (await) { }
				}

				function parseRule(expr) {
					var result = {
						expr:  expr,
						xpath: false,
						mods:  {single: false, attr: null}
					};

					var match;

					if (expr[0] === '$') { // xpath
						if (match = result.expr.match(/:single$/)) {
							result.expr = expr.substr(0, result.expr.length - match[0].length);
							result.mods.single = true;
						}

						result.xpath = true;
						result.expr  = result.expr.substr(1);
					} 
					else { // css selector
						if (match = result.expr.match(/(:single|:attr\(.+?\))+$/)) {
							match = match[0];
							result.expr = result.expr.substr(0, result.expr.length - match.length);

							while (match.length > 0) {
								if (match.indexOf(':single') === 0) {
									result.mods.single = true;
									match = match.substr(7);
								}
								else if (match.indexOf(':attr(') === 0) {
									var subMatch = match.match(/^:attr\((.+?)\)/);
									if (subMatch) {
										result.mods.attr = subMatch[1];
										match = match.substr(subMatch[0].length);
									} else {
										break;
									}
								}
								else {
									break;
								}
							}
						}
					}

					return result;
				}

				function xpath2array(xpathResult){
					var nodes = [];
					while (true) {
						var node = xpathResult.iterateNext();
						if (node) {
							nodes.push(node);
						} else {
							break;
						}
					}
					return nodes;
				}

				function selectResult(nodes, single, attr, simpleValue) {
					if (single) {
						if (nodes.length) {
							var node = nodes[0];
							if (attr) {
								return typeof node.attributes[attr] !== 'undefined' 
									? (simpleValue ? node.attributes[attr].value : node.attributes[attr])
									: null;
							} 
							else if (simpleValue) {
								node = node.textContent;
							}
							return node;
						}
						return null;
					} else {
						return Array.prototype.map.apply(nodes, 
							[
								attr 
									? function(el) {
										return simpleValue ? el.attributes[attr].value : el.attributes[attr]; 
									}
									: function(el) {
										return simpleValue ? el.textContent : el; 
									}
							]
						);
					}
				}

				function select(expr, contextNode, simpleValue) {
					var meta = parseRule(expr), nodes;

					if (meta.xpath) {
						nodes = xpath2array(document.evaluate(meta.expr, contextNode || document));
					} else {
						nodes = meta.expr === '&' // self
							? [contextNode]
							: (contextNode || document).querySelectorAll(meta.expr);
					}

					return selectResult(nodes, meta.mods.single, meta.mods.attr, simpleValue);
				}
			
				function scrapRules(rules, contextNode) {
					var result = {}, rule;

					for	(var key in rules) {
						rule = rules[key];

						if (typeof rule === 'string') {
							result[key] = select(rule, contextNode, true);
						} 
						else {
							var list  = []
							  , nodes = typeof rule.$ === 'string' ? select(rule.$, contextNode) : [contextNode];

							delete rule.$;

							for (var i in nodes) {
								list.push( scrapRules(rule, nodes[i]) );
							}

							result[key] = list;
						}
					}

					return result;
				}
				
				if (config.await) {
					while (true) {
						var found = select(config.await);
						if (typeof found === 'string' || (found !== null && found.length > 0)) {
							break;
						} else {
							sleep(60);
						}
					}
				}
			
				if (config.timeout > 0) {
					sleep(config.timeout);
				}

				var a = document.createElement('a');
				return {
					pages: config.pagination ? select(config.pagination, document, true).map(function(u) { return a.href = u, a.href; }) : [], 
					data:  scrapRules(config.rules, document)
				};
			}, config);

			for (var i in scrapResult.pages) {
				if (typeof result[ scrapResult.pages[i] ] === 'undefined') {
					result[ scrapResult.pages[i] ] = null;
				}
			}

			result[url] = scrapResult.data;
		} else {
			result[url] = false;
		}

		queryNext();
	}
	
	if (config.isPost) {
		page.open(url, 'post', config.postData, onLoad);
	} else {
		page.open(url, onLoad);
	}
}

queryPage(config.url);

EOL;
	if (false !== file_put_contents($file, $script)) {
		$output  = [];
		$options = '--load-images=false' . ($_SCRIPT_ARGS[ARG_DEBUG] ? ' --debug=true --remote-debugger-port=9000' : '');
		$path    = getDir($_SCRIPT_ARGS[ARG_PHANTOM]);
		$cmd     = "{$path}phantomjs {$options} {$file}";

		echo '-> Exec: ' . $cmd . PHP_EOL;
		exec($cmd, $output);
		unlink($file);

		end($output);
		return json_decode(current($output), true);
	}

	return false;
}

if (false === $result = query($config)) {
	die('Error: can not load data');
}

if ($_SCRIPT_ARGS[ARG_OUTPUT]) {
	if (false === file_put_contents(getPath($_SCRIPT_ARGS[ARG_OUTPUT]), json_encode($result, JSON_UNESCAPED_UNICODE))) {
		die('Error: can not write data to file');
	}
} 
else {
	die( json_encode($result, JSON_UNESCAPED_UNICODE) );
}
