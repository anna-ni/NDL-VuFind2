<?php
/**
 * Solr aspect of the Search Multi-class (Options)
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2015-2016.
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
 * @package  Search_Solr
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */
namespace Finna\Search\Solr;

/**
 * Solr Search Options
 *
 * @category VuFind
 * @package  Search_Solr
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */
class Options extends \VuFind\Search\Solr\Options
{
    use \Finna\Search\FinnaOptions;
    use \Finna\I18n\Translator\TranslatorAwareTrait;

    /**
     * Browse route
     *
     * @var string
     */
    protected $browseAction = null;

    /**
     * Date range visualization settings
     *
     * @var string
     */
    protected $dateRangeVis = '';


    /**
     * Geographic facets
     *
     * @var array
     */
    protected $geographic = '';

    /**
     * Constructor
     *
     * @param \VuFind\Config\PluginManager $configLoader Config loader
     */
    public function __construct(\VuFind\Config\PluginManager $configLoader)
    {
        parent::__construct($configLoader);

        $facetSettings = $configLoader->get($this->facetsIni);
        if (isset($facetSettings->SpecialFacets->dateRangeVis)) {
            $this->dateRangeVis = $facetSettings->SpecialFacets->dateRangeVis;
        }
        if (isset($facetSettings->SpecialFacets->geographic)) {
            $this->geographic
                = $facetSettings->SpecialFacets->geographic;
        }
    }

    /**
     * Set the route name for the browse action.
     *
     * @param string $action Route
     *
     * @return string
     */
    public function setBrowseAction($action)
    {
        $this->browseAction = $action;
    }

    /**
     * Get the field used for date range search
     *
     * @return string
     */
    public function getDateRangeSearchField()
    {
        list($field) = explode(':', $this->dateRangeVis);
        return $field;
    }

    /**
     * Get the field used for date range visualization
     *
     * @return string
     */
    public function getDateRangeVisualizationField()
    {
        $fields = explode(':', $this->dateRangeVis);
        return isset($fields[1]) ? $fields[1] : '';
    }

    /**
     * Translate a field name to a displayable string for rendering a query in
     * human-readable format:
     *
     * @param string $field Field name to display.
     *
     * @return string       Human-readable version of field name.
     */
    public function getHumanReadableFieldName($field)
    {
        $result = parent::getHumanReadableFieldName($field);
        if ($result != $field) {
            return $result;
        }
        return $this->translate("search_field_$field", null, $field);
    }

    /**
     * Return the route name for the search results action.
     *
     * @return string
     */
    public function getSearchAction()
    {
        return $this->browseAction ?: parent::getSearchAction();
    }

    /**
     * Get the field used for geographic facet
     *
     * @return string
     */
    public function getGeographicSearchField()
    {
        list($field) = explode(':', $this->geographic);
        return $field;
    }
}
