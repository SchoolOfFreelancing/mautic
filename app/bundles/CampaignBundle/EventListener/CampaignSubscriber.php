<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace Mautic\CampaignBundle\EventListener;

use Joomla\Http\Http;
use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event as Events;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use Mautic\CoreBundle\EventListener\CommonSubscriber;
use Mautic\CoreBundle\Helper\AbstractFormFieldHelper;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\CoreBundle\Model\AuditLogModel;
use Mautic\LeadBundle\Helper\TokenHelper;

/**
 * Class CampaignSubscriber.
 */
class CampaignSubscriber extends CommonSubscriber
{
    /**
     * @var IpLookupHelper
     */
    protected $ipLookupHelper;

    /**
     * @var AuditLogModel
     */
    protected $auditLogModel;

    /**
     * @var Http
     */
    protected $connector;

    /**
     * CampaignSubscriber constructor.
     *
     * @param IpLookupHelper $ipLookupHelper
     * @param AuditLogModel  $auditLogModel
     * @param Http           $connector
     */
    public function __construct(IpLookupHelper $ipLookupHelper, AuditLogModel $auditLogModel, Http $connector)
    {
        $this->ipLookupHelper = $ipLookupHelper;
        $this->auditLogModel  = $auditLogModel;
        $this->connector      = $connector;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            CampaignEvents::CAMPAIGN_POST_SAVE         => ['onCampaignPostSave', 0],
            CampaignEvents::CAMPAIGN_POST_DELETE       => ['onCampaignDelete', 0],
            CampaignEvents::CAMPAIGN_ON_BUILD          => ['onCampaignBuild', 0],
            CampaignEvents::ON_CAMPAIGN_TRIGGER_ACTION => ['onCampaignTriggerAction', 1],
        ];
    }

    /**
     * @param CampaignExecutionEvent $event
     *
     * @return CampaignExecutionEvent
     */
    public function onCampaignTriggerAction(CampaignExecutionEvent $event)
    {
        if (!$event->checkContext('campaign.remoteurl')) {
            return;
        }
        $lead   = $event->getLead();
        $config = $event->getConfig();
        try {
            $url    = $config['url'];
            $method = $config['method'];
            $data   = !empty($config['additional_data']['list']) ? $config['additional_data']['list'] : '';
            $data   = array_flip(AbstractFormFieldHelper::parseList($data));
            // replace contacts tokens
            foreach ($data as $key => $value) {
                $data[$key] = TokenHelper::findLeadTokens($value, $lead->getProfileFields(), true);
            }
            $headers = !empty($config['headers']['list']) ? $config['headers']['list'] : '';
            $headers = array_flip(AbstractFormFieldHelper::parseList($headers));
            foreach ($headers as $key => $value) {
                $headers[$key] = TokenHelper::findLeadTokens($value, $lead->getProfileFields(), true);
            }
            $timeout = $config['timeout'];

            if (in_array($method, ['get', 'trace'])) {
                $response = $this->connector->$method(
                    $url.(parse_url($url, PHP_URL_QUERY) ? '&' : '?').http_build_query($data),
                    $headers,
                    $timeout
                );
            } elseif (in_array($method, ['post', 'put', 'patch'])) {
                $response = $this->connector->$method(
                    $url,
                    $data,
                    $headers,
                    $timeout
                );
            } elseif ($method == 'delete') {
                $response = $this->connector->$method(
                    $url,
                    $headers,
                    $timeout,
                    $data
                );
            }
            if (in_array($response->code, [200, 201])) {
                return $event->setResult(true);
            }
        } catch (\Exception $e) {
            return $event->setFailed($e->getMessage());
        }

        return $event->setFailed('Error code: '.$response->code);
    }

    /**
     * Add an entry to the audit log.
     *
     * @param Events\CampaignEvent $event
     */
    public function onCampaignPostSave(Events\CampaignEvent $event)
    {
        $campaign = $event->getCampaign();
        $details  = $event->getChanges();

        //don't set leads
        unset($details['leads']);

        if (!empty($details)) {
            $log = [
                'bundle'    => 'campaign',
                'object'    => 'campaign',
                'objectId'  => $campaign->getId(),
                'action'    => ($event->isNew()) ? 'create' : 'update',
                'details'   => $details,
                'ipAddress' => $this->ipLookupHelper->getIpAddressFromRequest(),
            ];
            $this->auditLogModel->writeToLog($log);
        }
    }

    /**
     * Add a delete entry to the audit log.
     *
     * @param Events\CampaignEvent $event
     */
    public function onCampaignDelete(Events\CampaignEvent $event)
    {
        $campaign = $event->getCampaign();
        $log      = [
            'bundle'    => 'campaign',
            'object'    => 'campaign',
            'objectId'  => $campaign->deletedId,
            'action'    => 'delete',
            'details'   => ['name' => $campaign->getName()],
            'ipAddress' => $this->ipLookupHelper->getIpAddressFromRequest(),
        ];
        $this->auditLogModel->writeToLog($log);
    }

    /**
     * Add event triggers and actions.
     *
     * @param Events\CampaignBuilderEvent $event
     */
    public function onCampaignBuild(Events\CampaignBuilderEvent $event)
    {
        //Add action to actually add/remove lead to a specific lists
        $addRemoveLeadAction = [
            'label'           => 'mautic.campaign.event.addremovelead',
            'description'     => 'mautic.campaign.event.addremovelead_descr',
            'formType'        => 'campaignevent_addremovelead',
            'formTypeOptions' => [
                'include_this' => true,
            ],
            'callback' => '\Mautic\CampaignBundle\Helper\CampaignEventHelper::addRemoveLead',
        ];
        $event->addAction('campaign.addremovelead', $addRemoveLeadAction);

        //Add action to remote url call
        $remoteUrlAction = [
            'label'       => 'mautic.campaign.event.remoteurl',
            'description' => 'mautic.campaign.event.remoteurl_desc',
            'formType'    => 'campaignevent_remoteurl',
            'eventName'   => CampaignEvents::ON_CAMPAIGN_TRIGGER_ACTION,
            'formType'    => 'campaignevent_remoteurl',
        ];
        $event->addAction('campaign.remoteurl', $remoteUrlAction);
    }
}
