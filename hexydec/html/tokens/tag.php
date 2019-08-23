<?php
namespace hexydec\html;

class tag {

	protected $root;
	protected $parent = null;
	protected $tagName = null;
	protected $attributes = Array();
	protected $singleton = false;
	protected $children = Array();
	public $close = true;

	public function __construct(htmldoc $root, string $tag = null, tag $parent = null) {
		$this->root = $root;
		$this->tagName = $tag;
		$this->parent = $parent;
	}

	/**
	 * Parses an array of tokens into an HTML documents
	 *
	 * @param array &$tokens An array of tokens generated by tokenise()
	 * @param array $config An array of configuration options
	 * @return bool Whether the parser was able to capture any objects
	 */
	public function parse(array &$tokens) {

		// cache vars
		$config = $this->root->getConfig();
		$tag = $this->tagName;
		$attributes = Array();

		// parse tokens
		$attr = false;
		while (($token = next($tokens)) !== false) {
			switch ($token['type']) {

				// remember attribute
				case 'attribute':
					if ($attr) {
						$attributes[$attr] = null;
						$attr = false;
					}
					$attr = $token['value'];

					// cache attribute for minifier
					//$this->attributes[$attr] = isset($this->attributes[$attr]) ? $this->attributes[$attr] + 1 : 1;
					break;

				// record attribute and value
				case 'attributevalue':
					if ($attr) {
						$value = trim($token['value'], "= \t\r\n");
						if (strpos($value, '"') === 0 || strpos($value, "'") === 0) {
							$value = substr($value, 1, -1);
						}
						$attributes[$attr] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
						$attr = false;
					}
					break;

				case 'tagopenend':
					if (!in_array($tag, $config['elements']['singleton'])) {
						next($tokens);
						$this->children = $this->parseChildren($tokens, $config);
						break;
					} else {
						$this->singleton = $token['value'];
						break 2;
					}

				case 'tagselfclose':
					$this->singleton = $token['value'];
					break 2;

				case 'tagopenstart':
					$tag = trim($token['value'], '<');
					if ($tag == $tag) {
						$this->close = false;
					}
					prev($tokens);
					break 2;

				case 'tagclose':
					$close = trim($token['value'], '</>');
					if (strtolower($close) != strtolower($tag)) { // if tags not the same, go back to previous level

						// if the closing tag is optional then don't close the tag
						if (in_array($tag, $config['elements']['closeoptional'])) {
							$this->close = false;
						}
						prev($tokens); // close the tag on each level below until we find itself
					}
					break 2;
			}
		}
		if ($attr) {
			$attributes[$attr] = null;
		}
		if ($attributes) {
			$this->attributes = $attributes;
		}
	}

	/**
	 * Parses an array of tokens into an HTML documents
	 *
	 * @param array &$tokens An array of tokens generated by tokenise()
	 * @param array $config An array of configuration options
	 * @return bool Whether the parser was able to capture any objects
	 */
	public function parseChildren(array &$tokens) : array {
		$parenttag = $this->tagName;
		$config = $this->root->getConfig('elements');
		$children = Array();

		// process custom tags
		if (in_array($parenttag, $config['custom'])) {
			$class = '\\hexydec\\html\\'.$parenttag;
			$item = new $class($this->root);
			$item->parse($tokens);
			$children[] = $item;

		// parse children
		} else {
			$tag = null;
			$token = current($tokens);
			do {
				switch ($token['type']) {
					case 'doctype':
						$item = new doctype();
						$item->parse($tokens);
						$children[] = $item;
						break;

					case 'tagopenstart':
						$tag = trim($token['value'], '<');
						if ($tag == $parenttag && in_array($tag, $config['closeoptional'])) {
							prev($tokens);
							break 2;
						} else {

							// parse the tag
							$item = new tag($this->root, $tag, $this);
							$item->parse($tokens);
							$children[] = $item;
							if (in_array($tag, $config['singleton'])) {
								$tag = null;
							}
						}
						break;

					case 'tagclose':
						prev($tokens); // close the tag on each level below until we find itself
						break 2;

					case 'textnode':
						$item = new text($this->root, $this);
						$item->parse($tokens);
						$children[] = $item;
						break;

					case 'cdata':
						$item = new cdata();
						$item->parse($tokens);
						$children[] = $item;
						break;

					case 'comment':
						$item = new comment();
						$item->parse($tokens);
						$children[] = $item;
						break;
				}
			} while (($token = next($tokens)) !== false);
		}
		return $children;
	}

	public function minify(array $minify) {
		$config = $this->root->getConfig();
		$attr = $config['attributes'];
		if ($minify['lowercase']) {
			$this->tagName = strtolower($this->tagName);
		}

		// minify attributes
		$folder = false;
		foreach ($this->attributes AS $key => $value) {

			// lowercase attribute key
			if ($minify['lowercase']) {
				unset($this->attributes[$key]);
				$key = strtolower($key);
				$this->attributes[$key] = $value;
			}

			// minify attributes
			if ($minify['attributes']) {

				// trim attribute
				$value = $this->attributes[$key] = trim($value);

				// boolean attributes
				if ($minify['attributes']['boolean'] && in_array($key, $attr['boolean'])) {
					$this->attributes[$key] = null;

				// minify style tag
				} elseif ($key == 'style' && $minify['attributes']['style']) {
					$this->attributes[$key] = trim(str_replace(
						Array('  ', ' : ', ': ', ' :', ' ; ', ' ;', '; '),
						Array(' ', ':', ':', ':', ';', ';', ';'),
						$value
					), '; ');

				// sort classes
				} elseif ($key == 'class' && $minify['attributes']['class'] && strpos($value, ' ') !== false) {
					$class = array_filter(explode(' ', $value));
					sort($class);
					$this->attributes[$key] = implode(' ', $class);

				// minify option tag
				} elseif ($key == 'value' && $minify['attributes']['option'] && $this->tagName == 'option' && isset($this->children[0]) && $this->children[0]->text() == $value) {
					unset($this->attributes[$key]);

				// remove tag specific default attribute
				} elseif ($minify['attributes']['default'] && isset($attr['default'][$this->tagName][$key]) && ($attr['default'][$this->tagName][$key] === true || $attr['default'][$this->tagName][$key] == $value)) {
					unset($this->attributes[$key]);
				}

				// remove other attributes
				if ($value === '' && $minify['attributes']['empty'] && in_array($key, $attr['empty'])) {
					unset($this->attributes[$key]);
				}
			}

			// minify urls
			if ($minify['urls'] && in_array($key, $attr['urls'])) {

				// strip scheme from absolute URL's if the same as current scheme
				if ($minify['urls']['scheme']) {
					if (!isset($scheme)) {
						$scheme = 'http'.(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off' ? 's' : '').'://';
					}
					if (strpos($this->attributes[$key], $scheme) === 0) {
						$this->attributes[$key] = substr($this->attributes[$key], strlen($scheme)-2);
					}
				}

				// remove host for own domain
				if ($minify['urls']['host']) {
					if (!isset($host)) {
						$host = '//'.$_SERVER['HTTP_HOST'];
						$hostlen = strlen($host);
					}
					if (strpos($this->attributes[$key], $host) === 0 && (strlen($this->attributes[$key]) == $hostlen || strpos($this->attributes[$key], '/', 2)) == $hostlen + 1) {
						$this->attributes[$key] = substr($this->attributes[$key], $hostlen);
					}
				}

				// make absolute URLs relative
				if ($minify['urls']['absolute']) {

					// set folder variable
					if (!$folder) {
						$folder = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
						if (substr($folder, -1) != '/') {
							$folder = dirname($folder).'/';
						}
					}

					// minify
					if (strpos($this->attributes[$key], $folder) === 0) {
						$this->attributes[$key] = substr($this->attributes[$key], strlen($folder));
					}
				}
			}
		}

		// minify singleton closing style
		if ($minify['singleton'] && $this->singleton) {
			$this->singleton = '>';
		}

		// work out whether to omit the closing tag
		if ($minify['close'] && in_array($this->tagName, $config['elements']['closeoptional'])) {
			$tag = null;
			$children = $this->parent->toArray();
			$last = end($children);
			$next = false;
			foreach ($children AS $item) {

				// find self in siblings
				if ($item === $this) {
					$next = true;

				// find next tag
				} elseif ($next) {
					$type = get_class($item);

					// if type is not text or the text content is empty
					if ($type != 'hexydec\\html\\text' || !$item->content) {

						// if the next tag is optinally closable too, then we can remove the closing tag of this
						if ($type == 'hexydec\\html\\tag' && in_array($item->tagName, $config['elements']['closeoptional'])) {
							$this->close = false;
						}

						// indicate we have process this
						$next = false;
						break;
					}
				}
			}

			// if last tag, remove closing tag
			if ($next) {
				$this->close = false;
			}
		}

		// sort attributes
		// if ($config['attributes']['sort']) {
		// 	$attr = $this->attributes;
		// 	$this->attributes = Array();
		// 	foreach ($config['attributes']['sort'] AS $key) {
		// 		if (isset($attr[$key])) {
		// 			$this->attributes[$key] = $attr[$key];
		// 		}
		// 	}
		// }

		// minify children
		if ($this->children) {
			if (in_array($this->tagName, $config['elements']['pre'])) {
				$minify['whitespace'] = false;
			}
			foreach ($this->children AS $item) {
				$item->minify($minify);
			}
		}
	}

	public function find(Array $selector) : Array {
		$found = Array();
		$match = true;
		$searchChildren = true;
		foreach ($selector AS $i => $item) {

			// only search this level
			if ($item['join'] == '>' && !$i) {
				$searchChildren = false;
			}

			// pass rest of selector to level below
			if ($item['join'] && $i) {
				$match = false;
				foreach ($this->children AS $child) {
					if (get_class($child) == 'hexydec\\html\\tag') {
						$found = array_merge($found, $child->find(array_slice($selector, $i)));
					}
				}
				break;

			// match tag
			} elseif (!empty($item['tag']) && $item['tag'] != '*') {
				if ($item['tag'] != $this->tagName) {
					$match = false;
					break;
				}

			// match id
			} elseif (!empty($item['id'])) {
				if (empty($this->attributes['id']) || $item['id'] != $this->attributes['id']) {
					$match = false;
					break;
				}

			// match class
			} elseif (!empty($item['class'])) {
				if (empty($this->attributes['class']) || !in_array($item['class'], explode(' ', $this->attributes['class']))) {
					$match = false;
					break;
				}

			// attribute selector
			} elseif (!empty($item['attribute'])) {

				// check if attribute exists
				if (empty($this->attributes[$item['attribute']])) {
					$match = false;
					break;
				} elseif (!empty($item['value'])) {

					// exact match
					if ($item['comparison'] == '=') {
						if ($this->attributes[$item['attribute']] != $item['value']) {
							$match = false;
							break;
						}

					// match start
					} elseif ($item['comparison'] == '^=') {
						if (strpos($this->attributes[$item['attribute']], $item['value']) !== 0) {
							$match = false;
							break;
						}

					// match within
					} elseif ($item['comparison'] == '*=') {
						if (strpos($this->attributes[$item['attribute']], $item['value']) === false) {
							$match = false;
							break;
						}

					// match end
					} elseif ($item['comparison'] == '$=') {
						if (strpos($this->attributes[$item['attribute']], $item['value']) !== strlen($this->attributes[$item['attribute']]) - strlen($item['value'])) {
							$match = false;
							break;
						}
					}
				}

			// match pseudo selector
			} elseif (!empty($item['pseudo'])) {
				$children = $this->parent->children();

				// match first-child
				if ($item['pseudo'] == 'first-child') {
					if (!isset($children[0]) || $this !== $children[0]) {
						$match = false;
						break;
					}

				// match last child
				} elseif ($item['pseudo'] == 'last-child') {
					if (($last = end($children)) === false || $this !== $last) {
						$match = false;
						break;
					}
				}
			}
		}
		if ($match) {
			$found[] = $this;
		}
		if ($searchChildren && $this->children) {
			foreach ($this->children AS $child) {
				if (get_class($child) == 'hexydec\\html\\tag') {
					$found = array_merge($found, $child->find($selector));
				}
			}
		}
		return $found;
	}

	public function attr(string $key) : ?string {
		if (isset($this->attributes[$key])) {
			return $this->attributes[$key];
		}
		return null;
	}

	public function text() : array {
		$text = Array();
		foreach ($this->children AS $item) {

			// only get text from these objects
			if (in_array(get_class($item), Array('hexydec\\html\\tag', 'hexydec\\html\\text'))) {
				$value = $item->text();
				$text = array_merge($text, is_array($value) ? $value : Array($value));
			}
		}
		return $text;
	}

	public function html(array $options = null) : string {

		// compile attributes
		$html = '<'.$this->tagName;
		foreach ($this->attributes AS $key => $value) {
			$html .= ' '.$key;
			if ($value !== null || $options['xml']) {
				$empty = in_array($value, Array(null, ''));
				$quote = '"';
				if ($options['quotestyle'] == 'single') {
					$quote = "'";
				} elseif (!$empty && $options['quotestyle'] == 'minimal' && strcspn($value, " =\"'`<>\n\r\t/") == strlen($value)) {
					$quote = '';
				}
				if (!$empty) {
					$value = htmlspecialchars($value, ENT_HTML5 | ($options['quotestyle'] == 'single' ? ENT_QUOTES : ENT_COMPAT));
				}
				$html .= '='.$quote.$value.$quote;
			}
		}

		// close singleton tags
		if ($this->singleton) {
			$html .= empty($options['singletonclose']) ? $this->singleton : $options['singletonclose'];

		// close opening tag and compile contents
		} else {
			$html .= '>';
			foreach ($this->children AS $item) {
				$html .= $item->html($options);
			}
			if ($options['closetags'] || $this->close) {
				$html .= '</'.$this->tagName.'>';
			}
		}
		return $html;
	}

	public function toArray() {
		return $this->children;
	}

	public function children() {
		$children = Array();
		foreach ($this->children AS $item) {
			if (get_class($item) == 'hexydec\\html\\tag') {
				$children[] = $item;
			}
		}
		return $children;
	}

	public function __get($var) {
		return $this->$var;
	}
}