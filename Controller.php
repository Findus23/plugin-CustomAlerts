<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html Gpl v3 or later
 * @version $Id$
 *
 */

namespace Piwik\Plugins\CustomAlerts;

use Piwik\Common;
use Piwik\Date;
use Piwik\Db;
use Piwik\Period;
use Piwik\Piwik;
use Piwik\Plugin\Manager as PluginManager;
use Piwik\Plugins\API\ProcessedReport;
use Piwik\Plugins\SitesManager\API as SitesManagerApi;
use Piwik\Plugins\MobileMessaging\API as APIMobileMessaging;
use Piwik\Site;
use Piwik\View;

/**
  *
 */
class Controller extends \Piwik\Plugin\ControllerAdmin
{
    /**
     * @var ProcessedReport
     */
    private $processedReport;

    public function __construct(ProcessedReport $processedReport)
    {
        $this->processedReport = $processedReport;
        parent::__construct();
    }

	/**
	 * Shows all Alerts of the current selected idSite.
	 */
	public function index()
	{
        $view = new View('@CustomAlerts/index');
        $this->setGeneralVariablesView($view);

        $idSites = $this->getSiteIdsHavingAccess();
        $alerts  = API::getInstance()->getAlerts($idSites);

        foreach ($alerts as &$alert) {
            $alert['reportName'] = $this->findReportName($alert);
            $alert['siteName']   = $this->findSiteName($alert);
        }

        $view->alerts = $alerts;

		return $view->render();
	}

	public function historyTriggeredAlerts()
	{
        $view = new View('@CustomAlerts/historyTriggeredAlerts');
        $this->setGeneralVariablesView($view);

        $idSites = $this->getSiteIdsHavingAccess();
        $alerts  = API::getInstance()->getTriggeredAlerts($idSites);
        array_slice($alerts, 0, 100);
        $alerts  = array_reverse($alerts);

        $view->alertsFormatted = $this->formatAlerts($alerts, 'html_extended');

		return $view->render();
	}

	public function addNewAlert()
	{
        $view = new View('@CustomAlerts/addNewAlert');
		$this->setGeneralVariablesView($view);
        $this->addBasicCreateAndEditVariables($view, null);

        $view->currentSite = array('id' => $this->idSite, 'name' => $this->site->getName());

		return $view->render();
	}

	public function editAlert()
	{
		$idAlert = Common::getRequestVar('idAlert', null, 'int');

        $view = new View('@CustomAlerts/editAlert');
		$this->setGeneralVariablesView($view);

        $alert = API::getInstance()->getAlert($idAlert);
        $view->currentSite = array('id' => $this->findSiteId($alert), 'name' => $this->findSiteName($alert));

        $this->addBasicCreateAndEditVariables($view, $alert);

		return $view->render();
	}

    /**
     * Returns the Alerts that were triggered in $format.
     *
     * @param array $triggeredAlerts
     * @param string $format Can be 'html' or 'tsv'
     * @throws \Exception
     * @return string
     */
    public function formatAlerts($triggeredAlerts, $format)
    {
        switch ($format) {
            case 'html_extended':
                $view = new View('@CustomAlerts/htmlTriggeredAlerts');
                $view->triggeredAlerts = $this->enrichTriggeredAlerts($triggeredAlerts);
                $view->hasConditions   = count(array_filter($triggeredAlerts, function($alert) {
                    return !empty($alert['report_condition']);
                }));
                $view->extended        = true;

                return $view->render();

            case 'html':
                $view = new View('@CustomAlerts/htmlTriggeredAlerts');
                $view->triggeredAlerts = $this->enrichTriggeredAlerts($triggeredAlerts);
                $view->hasConditions   = count(array_filter($triggeredAlerts, function($alert) {
                    return !empty($alert['report_condition']);
                }));
                $view->extended        = false;

                return $view->render();

            case 'sms':

                $view = new View('@CustomAlerts/smsTriggeredAlerts');
                $view->triggeredAlerts = $this->enrichTriggeredAlerts($triggeredAlerts);

                return $view->render();

            case 'text':

                $view = new View('@CustomAlerts/textTriggeredAlerts');
                $view->triggeredAlerts = $this->enrichTriggeredAlerts($triggeredAlerts);

                return $view->render();
        }

        throw new \Exception('Unsupported format');
    }

    private function addBasicCreateAndEditVariables($view, $alert)
    {
        $view->alert = $alert;

        $alertGroupConditions = array();
        foreach (Processor::getGroupConditions() as $condName => $condValue) {
            $alertGroupConditions[] = array('key' => $condValue, 'value' => Piwik::translate($condName));
        }

        $view->alertGroupConditions = $alertGroupConditions;

        $comparablesDates = array();
        foreach (Processor::getComparablesDates() as $period => $comparablesDatesPeriod) {
            $comparablesDates[$period] = array();
            foreach ($comparablesDatesPeriod as $compDateTranslation => $key) {
                $comparablesDates[$period][] = array('key' => (string)$key, 'value' => Piwik::translate($compDateTranslation));
            }
        }

        $view->currentUserEmail = Piwik::getCurrentUserEmail();
        $view->comparablesDates   = $comparablesDates;
        $view->reportMetadata     = $this->findReportMetadata($alert);
        $view->supportsSMS        = $this->supportsSms();
        $view->periodOptions = array(
            array('key' => 'day', 'value' => Piwik::translate('Intl_PeriodDay')),
            array('key' => 'week', 'value' => Piwik::translate('Intl_PeriodWeek')),
            array('key' => 'month', 'value' => Piwik::translate('Intl_PeriodMonth')),
        );

        $numbers = APIMobileMessaging::getInstance()->getActivatedPhoneNumbers();

        $phoneNumbers = array();
        if (!empty($numbers)) {
            foreach ($numbers as $number) {
                $phoneNumbers[$number] = $number;
            }
        }

        $view->phoneNumbers = $phoneNumbers;

        $metricConditionOptions = array();
        foreach (Processor::getMetricConditions() as $condName => $condValue) {
            $metricConditionOptions[] = array('key' => $condValue, 'value' => Piwik::translate($condName));
        }
        $view->metricConditionOptions = $metricConditionOptions;
    }

    private function supportsSms()
    {
        return PluginManager::getInstance()->isPluginActivated('MobileMessaging');
    }

    private function findReportMetadata($alert)
    {
        $idSite = $this->findSiteId($alert);

        if (empty($idSite)) {
            return;
        }

        $report = $this->processedReport->getReportMetadataByUniqueId($idSite, $alert['report']);

        return $report;
    }

    private function findReportName($alert)
    {
        $report = $this->findReportMetadata($alert);

        if (!empty($report)) {
            return $report['name'];
        }
    }

    private function findSiteName($alert)
    {
        $idSite = $this->findSiteId($alert);

        if (!empty($idSite)) {

            return Site::getNameFor($idSite);
        }
    }

    private function findSiteId($alert)
    {
        if (empty($alert)) {
            return;
        }

        // triggered alert
        if (array_key_exists('idsite', $alert)) {
            return $alert['idsite'];
        }

        // regular alert
        if (array_key_exists('id_sites', $alert) && !empty($alert['id_sites'])) {
            list($idSite) = $alert['id_sites'];
            return $idSite;
        }
    }

    private function getSiteIdsHavingAccess()
    {
        return SitesManagerApi::getInstance()->getSitesIdWithAtLeastViewAccess();
    }

    protected function enrichTriggeredAlerts($triggeredAlerts)
    {
        $cached = array();
        foreach ($triggeredAlerts as &$alert) {
            $idSite = $alert['idsite'];
            $metric = $alert['metric'];
            $report = $alert['report'];

            if (!array_key_exists($idSite, $cached)) {
                $cached[$idSite] = array('report' => array(), 'metric' => array(), 'siteName' => '', 'siteTimezone' => null);
            }

            if (empty($cached[$idSite]['siteName'])) {
                $cached[$idSite]['siteName'] = $this->findSiteName($alert);
            }

            if (empty($cached[$idSite]['siteTimezone']) && !empty($cached[$idSite]['siteName'])) {
                $cached[$idSite]['siteTimezone'] = Site::getTimezoneFor($idSite);
            }

            if (!array_key_exists($report, $cached[$idSite]['report'])) {
                $cached[$idSite]['report'][$report] = $this->findReportMetadata($alert);
                $cached[$idSite]['metric'][$report] = array();
            }

            if (is_array($cached[$idSite]['metric'][$report]) && !array_key_exists($metric, $cached[$idSite]['metric'][$report])) {
                $cached[$idSite]['metric'][$report][$metric] = $this->processedReport->translateMetric($metric, $idSite, $alert['report']);
            }
        }

        foreach ($triggeredAlerts as &$alert) {
            $idSite = $alert['idsite'];
            $metric = $alert['metric'];
            $report = $alert['report'];
            $cachedSite = $cached[$idSite];

            $alert['value_old']    = (int) $alert['value_old'] == $alert['value_old'] ? (int) $alert['value_old'] : $alert['value_old'];
            $alert['value_new']    = (int) $alert['value_new'] == $alert['value_new'] ? (int) $alert['value_new'] : $alert['value_new'];
            $alert['reportName']   = null;
            $alert['dimension']    = null;
            $alert['reportMetric'] = !empty($cachedSite['metric'][$report][$metric]) ? $cachedSite['metric'][$report][$metric] : null;
            $alert['reportConditionName'] = null;
            $alert['siteName']     = $cached[$idSite]['siteName'];
            $alert['ts_triggered'] = $this->getPrettyDateForSite($alert['ts_triggered'], $alert['period'], $cachedSite['siteTimezone']);

            if (!empty($cachedSite['report'][$report])) {
                $reportMetadata = $cachedSite['report'][$report];

                $alert['reportName'] = $reportMetadata['name'];
                $alert['dimension']  = !empty($reportMetadata['dimension']) ? $reportMetadata['dimension'] : null;

                $conditionTranslation = array_search($alert['report_condition'], Processor::getGroupConditions(), true);
                $alert['reportConditionName'] = $conditionTranslation ? Piwik::translate($conditionTranslation) : null;
            }
        }

        return $triggeredAlerts;
    }

    private function getPrettyDateForSite($datetime, $period, $timezone)
    {
        $date = Date::factory($datetime, $timezone);
        // we ran the alerts for the period before...
        $date = $date->subPeriod(1, $period);

        $period     = Period\Factory::build($period, $date);
        $prettyDate = $period->getLocalizedShortString();

        return $prettyDate;
    }
}
