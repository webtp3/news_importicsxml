<?php

namespace PicoFeed\Filter;

use PicoFeed\Client\Url;
use PicoFeed\Config\Config;
use PicoFeed\Parser\XmlParser;
use PicoFeed\Scraper\RuleLoader;

/**
 * HTML Filter class
 *
 * @author  Frederic Guillot
 */
class Html
{
    /**
     * Config object
     *
     * @access private
     * @var \PicoFeed\Config\Config
     */
    private $config;

    /**
     * Unfiltered XML data
     *
     * @access private
     * @var string
     */
    private $input = '';

    /**
     * Filtered XML data
     *
     * @access private
     * @var string
     */
    private $output = '';

    /**
     * List of empty tags
     *
     * @access private
     * @var array
     */
    private $empty_tags = [];

    /**
     * Empty flag
     *
     * @access private
     * @var bool
     */
    private $empty = true;

    /**
     * Tag instance
     *
     * @access public
     * @var \PicoFeed\Filter\Tag
     */
    public $tag = '';

    /**
     * Attribute instance
     *
     * @access public
     * @var \PicoFeed\Filter\Attribute
     */
    public $attribute = '';

    /**
     * The website to filter
     *
     * @access private
     * @var string
     */
    private $website;

    /**
     * Initialize the filter, all inputs data must be encoded in UTF-8 before
     *
     * @access public
     * @param  string  $html      HTML content
     * @param  string  $website   Site URL (used to build absolute URL)
     */
    public function __construct($html, $website)
    {
        $this->input = XmlParser::HtmlToXml($html);
        $this->output = '';
        $this->tag = new Tag;
        $this->website = $website;
        $this->attribute = new Attribute(new Url($website));
    }

    /**
     * Set config object
     *
     * @access public
     * @param  \PicoFeed\Config\Config  $config   Config instance
     * @return \PicoFeed\Filter\Html
     */
    public function setConfig($config)
    {
        $this->config = $config;

        if ($this->config !== null) {
            $this->attribute->setImageProxyCallback($this->config->getFilterImageProxyCallback());
            $this->attribute->setImageProxyUrl($this->config->getFilterImageProxyUrl());
            $this->attribute->setImageProxyProtocol($this->config->getFilterImageProxyProtocol());
            $this->attribute->setIframeWhitelist($this->config->getFilterIframeWhitelist([]));
            $this->attribute->setIntegerAttributes($this->config->getFilterIntegerAttributes([]));
            $this->attribute->setAttributeOverrides($this->config->getFilterAttributeOverrides([]));
            $this->attribute->setRequiredAttributes($this->config->getFilterRequiredAttributes([]));
            $this->attribute->setMediaBlacklist($this->config->getFilterMediaBlacklist([]));
            $this->attribute->setMediaAttributes($this->config->getFilterMediaAttributes([]));
            $this->attribute->setSchemeWhitelist($this->config->getFilterSchemeWhitelist([]));
            $this->attribute->setWhitelistedAttributes($this->config->getFilterWhitelistedTags([]));
            $this->tag->setWhitelistedTags(array_keys($this->config->getFilterWhitelistedTags([])));
        }

        return $this;
    }

    /**
     * Run tags/attributes filtering
     *
     * @access public
     * @return string
     */
    public function execute()
    {
        $this->preFilter();

        $parser = xml_parser_create();

        xml_set_object($parser, $this);
        xml_set_element_handler($parser, 'startTag', 'endTag');
        xml_set_character_data_handler($parser, 'dataTag');
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
        xml_parse($parser, $this->input, true);
        xml_parser_free($parser);

        $this->postFilter();

        return $this->output;
    }

    /**
     * Called before XML parsing
     *
     * @access public
     */
    public function preFilter()
    {
        $this->input = $this->tag->removeBlacklistedTags($this->input);
    }

    /**
     * Called after XML parsing
     *
     * @access public
     */
    public function postFilter()
    {
        $this->output = $this->tag->removeEmptyTags($this->output);
        $this->output = $this->filterRules($this->output);
        $this->output = $this->tag->removeMultipleBreakTags($this->output);
        $this->output = trim($this->output);
    }

    /**
     * Called after XML parsing
     * @param string $content the content that should be filtered
     *
     * @access public
     */
    public function filterRules($content)
    {
        // the constructor should require a config, then this if can be removed
        if ($this->config === null) {
            $config = new Config;
        } else {
            $config = $this->config;
        }

        $loader = new RuleLoader($config);
        $rules = $loader->getRules($this->website);

        $url = new Url($this->website);
        $sub_url = $url->getFullPath();

        if (isset($rules['filter'])) {
            foreach ($rules['filter'] as $pattern => $rule) {
                if (preg_match($pattern, $sub_url)) {
                    foreach ($rule as $search => $replace) {
                        $content = preg_replace($search, $replace, $content);
                    }
                }
            }
        }

        return $content;
    }

    /**
     * Parse opening tag
     *
     * @access public
     * @param  resource  $parser       XML parser
     * @param  string    $tag          Tag name
     * @param  array     $attributes   Tag attributes
     */
    public function startTag($parser, $tag, array $attributes)
    {
        $this->empty = true;

        if ($this->tag->isAllowed($tag, $attributes)) {
            $attributes = $this->attribute->filter($tag, $attributes);

            if ($this->attribute->hasRequiredAttributes($tag, $attributes)) {
                $attributes = $this->attribute->addAttributes($tag, $attributes);

                $this->output .= $this->tag->openHtmlTag($tag, $this->attribute->toHtml($attributes));
                $this->empty = false;
            }
        }

        $this->empty_tags[] = $this->empty;
    }

    /**
     * Parse closing tag
     *
     * @access public
     * @param  resource  $parser    XML parser
     * @param  string    $tag       Tag name
     */
    public function endTag($parser, $tag)
    {
        if (! array_pop($this->empty_tags) && $this->tag->isAllowedTag($tag)) {
            $this->output .= $this->tag->closeHtmlTag($tag);
        }
    }

    /**
     * Parse tag content
     *
     * @access public
     * @param  resource  $parser    XML parser
     * @param  string    $content   Tag content
     */
    public function dataTag($parser, $content)
    {
        // Replace &nbsp; with normal space
        $content = str_replace("\xc2\xa0", ' ', $content);
        $this->output .= Filter::escape($content);
    }
}
