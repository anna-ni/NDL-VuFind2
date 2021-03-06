<?php

/**
 * Lucene query syntax helper class.
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2015-2017.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Search
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */
namespace FinnaSearch\Backend\Solr;

use VuFindCode\ISBN;
use VuFindSearch\Backend\Exception\BackendException;

/**
 * Lucene query syntax helper class.
 *
 * @category VuFind
 * @package  Search
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org
 */
class LuceneSyntaxHelper extends \VuFindSearch\Backend\Solr\LuceneSyntaxHelper
{
    /**
     * Unicode normalization form
     *
     * @var string
     */
    protected $unicodeNormalizationForm;

    /**
     * Search filters
     *
     * @var string
     */
    protected $searchFilters;

    /**
     * Constructor.
     *
     * @param bool|string $csBools                  Case sensitive Booleans setting
     * @param bool        $csRanges                 Case sensitive ranges setting
     * @param string      $unicodeNormalizationForm UNICODE normalization form
     * @param array       $searchFilters            Regexp filters defined invalid
     * searches
     */
    public function __construct(
        $csBools = true, $csRanges = true, $unicodeNormalizationForm = 'NFKC',
        $searchFilters = []
    ) {
        parent::__construct($csBools, $csRanges);
        $this->unicodeNormalizationForm = $unicodeNormalizationForm;
        $this->searchFilters = $searchFilters;
    }

    /**
     * Return normalized input string.
     *
     * @param string $searchString Input search string
     *
     * @return string
     */
    public function normalizeSearchString($searchString)
    {
        $searchString = parent::normalizeSearchString($searchString);
        $searchString = $this->normalizeUnicodeForm($searchString);
        $searchString = $this->normalizeISBN($searchString);

        foreach ($this->searchFilters as $i => $filter) {
            if (preg_match("/$filter/", $searchString)) {
                throw new BackendException(
                    "Search string '$searchString' matched filter '$filter'"
                );
            }
        }

        return $searchString;
    }

    /**
     * Perform final normalizations to a search string
     *
     * @param string $searchString Input search string
     *
     * @return string
     */
    public function finalizeSearchString($searchString)
    {
        return $this->normalizeUnquotedMinuses($searchString);
    }

    /**
     * Check if passed string is an ISBN and convert to ISBN-13
     *
     * @param string $searchString The query string
     *
     * @return valid ISBN-13 or the original string
     */
    protected function normalizeISBN($searchString)
    {
        if (!ISBN::isValidISBN10($searchString)) {
            return $searchString;
        }

        $isbn = new ISBN($searchString);
        return $isbn->get13();
    }

    /**
     * Normalize UNICODE form
     *
     * @param string $searchString Input search string
     *
     * @return string
     */
    protected function normalizeUnicodeForm($searchString)
    {
        switch ($this->unicodeNormalizationForm) {
        case 'NFC':
            return \Normalizer::normalize($searchString, \Normalizer::FORM_C);
        case 'NFD':
            return \Normalizer::normalize($searchString, \Normalizer::FORM_D);
        case 'NFKC':
            return \Normalizer::normalize($searchString, \Normalizer::FORM_KC);
        case 'NFKD':
            return \Normalizer::normalize($searchString, \Normalizer::FORM_KD);
        }

        return $searchString;
    }

    /**
     * Escape unquoted minus sign and convert occurrences of
     * unquoted exclamation + minus sign to minus.
     *
     * @param string $searchString Input search string
     *
     * @return string
     */
    protected function normalizeUnquotedMinuses($searchString)
    {
        $result = '';
        $inQuotes = false;
        $prev = '';
        $prev2 = '';
        foreach (str_split($searchString) as $c) {
            if ($c == '"') {
                $inQuotes = !$inQuotes;
            }
            if (!$inQuotes && $c == '-' && $prev != "\\") {
                if ($prev == '!' && $prev2 != "\\") {
                    $result = substr($result, 0, -1) . '-';
                } else {
                    $result .= "\\$c";
                }
            } else {
                $result .= $c;
            }
            $prev2 = $prev;
            $prev = $c;
        }

        return $result;
    }
}
