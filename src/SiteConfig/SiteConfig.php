<?php

declare(strict_types=1);

namespace Graby\SiteConfig;

/**
 * Site Config.
 *
 * Each instance of this class should hold extraction patterns and other directives
 * for a website. See ContentExtractor class to see how it's used.
 *
 * @author Keyvan Minoukadeh
 * @copyright 2013 Keyvan Minoukadeh
 * @license http://www.gnu.org/licenses/agpl-3.0.html AGPL v3
 */
class SiteConfig
{
    /**
     * Use first matching element as title (0 or more xpath expressions).
     *
     * @var string[]
     */
    public array $title = [];

    /**
     * Use first matching element as body (0 or more xpath expressions).
     *
     * @var string[]
     */
    public array $body = [];

    /**
     * Use first matching element as author (0 or more xpath expressions).
     *
     * @var string[]
     */
    public array $author = [];

    /**
     * Use first matching element as date (0 or more xpath expressions).
     *
     * @var string[]
     */
    public array $date = [];

    /**
     * Strip elements matching these xpath expressions (0 or more).
     *
     * @var string[]
     */
    public array $strip = [];

    /**
     * Attribute used to replace lazyload image (like `data-lazy-src`).
     */
    public ?string $src_lazy_load_attr = null;

    /**
     * Strip elements which contain these strings (0 or more) in the id or class attribute.
     *
     * @var string[]
     */
    public array $strip_id_or_class = [];

    /**
     * Strip images which contain these strings (0 or more) in the src attribute.
     *
     * @var string[]
     */
    public array $strip_image_src = [];

    /**
     * Mark article as a native ad if any of these expressions match (0 or more xpath expressions).
     *
     * @var string[]
     */
    public array $native_ad_clue = [];

    /**
     * Additional HTTP headers to send (associative array).
     *
     * @var array<string, string>
     */
    public array $http_header = [];

    /**
     * Process HTML with tidy before creating DOM (bool or null if undeclared).
     */
    public ?bool $tidy = null;

    /**
     * Autodetect title/body if xpath expressions fail to produce results.
     *     Note that this applies to title and body separately, ie.
     *       * if we get a body match but no title match, this option will determine whether we autodetect title
     *       * if neither match, this determines whether we autodetect title and body.
     *     Also note that this only applies when there is at least one xpath expression in title or body, ie.
     *       * if title and body are both empty (no xpath expressions), this option has no effect (both title and body will be auto-detected)
     *       * if there's an xpath expression for title and none for body, body will be auto-detected and this option will determine whether we auto-detect title if the xpath expression for it fails to produce results.
     *     Usage scenario: you want to extract something specific from a set of URLs, e.g. a table, and if the table is not found, you want to ignore the entry completely. Auto-detection is unlikely to succeed here, so you construct your patterns and set this option to false. Another scenario may be a site where auto-detection has proven to fail (or worse, picked up the wrong content).
     */
    public ?bool $autodetect_on_failure = null;

    /**
     * Clean up content block - attempt to remove elements that appear to be superfluous.
     */
    public ?bool $prune = null;

    /**
     * Test URL - if present, can be used to test the config above.
     *
     * @var string[]
     */
    public array $test_url = [];

    /**
     * If page contains - XPath expression. Used to determine if the preceding rule gets evaluated or not.
     * Currently only works with single_page_link & next_page_link (first one has priority over the second one).
     *
     * @var array{single_page_link: array<string,string>}|array{next_page_link: array<string,string>}|array{}
     */
    public array $if_page_contains = [];

    /**
     * Single-page link - should identify a link element or URL pointing to the page holding the entire article
     * This is useful for sites which split their articles across multiple pages. Links to such pages tend to
     * display the first page with links to the other pages at the bottom. Often there is also a link to a page
     * which displays the entire article on one page (e.g. 'print view').
     * This should be an XPath expression identifying the link to that page. If present and we find a match,
     * we will retrieve that page and the rest of the options in this config will be applied to the new page.
     *
     * @var string[]
     */
    public array $single_page_link = [];

    /**
     * @var string[]
     */
    public array $next_page_link = [];

    /**
     * Which parser to use for turning raw HTML into a DOMDocument (either 'libxml' or 'html5lib').
     */
    public ?string $parser = null;

    /**
     * Strings to search for in HTML before processing begins (used with $replace_string).
     *
     * @var string[]
     */
    public array $find_string = [];

    /**
     * Strings to replace those found in $find_string before HTML processing begins.
     *
     * @var string[]
     */
    public array $replace_string = [];

    /**
     * the options below cannot be set in the config files which this class represents.
     */
    public ?string $cache_key = null;

    /**
     * If fetching the site's content requires to authentify.
     */
    public bool $requires_login = false;

    /**
     * XPath query to detect if login is requested in a page from the site.
     */
    public string $not_logged_in_xpath;

    /**
     * Site's login form URI, if applicable.
     */
    public string $login_uri;

    /**
     * Name of the site's login form username field. Example: username.
     */
    public string $login_username_field;

    /**
     * Name of the site's login form password field. Example: password.
     */
    public string $login_password_field;

    /**
     * Extra fields to POST to the site's login form.
     *
     * @var array<int|string, string> hash of form field name => value
     */
    public array $login_extra_fields = [];

    /**
     * Explicitly skip getting data from JSON-LD.
     */
    public bool $skip_json_ld = false;

    /**
     * Wrap elements matching these xpath expressions with the specified tag (associative array).
     *
     * @var array<string, string>
     */
    public array $wrap_in = [];

    /**
     * Used if undeclared.
     */
    protected bool $default_tidy = true;

    /**
     * Used if undeclared.
     */
    protected bool $default_autodetect_on_failure = true;

    /**
     * Used if undeclared.
     */
    protected bool $default_prune = true;

    /**
     * Used if undeclared.
     */
    protected string $default_parser = 'libxml';

    /**
     * Process HTML with tidy before creating DOM (bool or null if undeclared).
     */
    public function tidy(bool $use_default = true): ?bool
    {
        if ($use_default) {
            return $this->tidy ?? $this->default_tidy;
        }

        return $this->tidy;
    }

    /**
     * Clean up content block - attempt to remove elements that appear to be superfluous.
     */
    public function prune(bool $use_default = true): ?bool
    {
        if ($use_default) {
            return $this->prune ?? $this->default_prune;
        }

        return $this->prune;
    }

    /**
     * Which parser to use for turning raw HTML into a DOMDocument (either 'libxml' or 'html5lib').
     */
    public function parser(bool $use_default = true): ?string
    {
        if ($use_default) {
            return $this->parser ?? $this->default_parser;
        }

        return $this->parser;
    }

    /**
     * Autodetect title/body if xpath expressions fail to produce results.
     */
    public function autodetect_on_failure(bool $use_default = true): ?bool
    {
        if ($use_default) {
            return $this->autodetect_on_failure ?? $this->default_autodetect_on_failure;
        }

        return $this->autodetect_on_failure;
    }

    /**
     * Return a condition for the given name (if exists).
     *
     * @param string $name  Rule name (only single_page_link & next_page_link is supported for now)
     * @param string $value Value of the rule (currently only an url)
     */
    public function getIfPageContainsCondition(string $name, string $value): ?string
    {
        if (isset($this->if_page_contains[$name]) && isset($this->if_page_contains[$name][$value])) {
            return $this->if_page_contains[$name][$value];
        }

        return null;
    }
}
