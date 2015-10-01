<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) CampaignChain Inc. <info@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Activity\FacebookBundle\Controller;

use CampaignChain\CoreBundle\Controller\Module\AbstractActivityModuleHandler;
use CampaignChain\CoreBundle\Entity\Activity;
use CampaignChain\CoreBundle\Entity\Location;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\HttpFoundation\Session\Session;
use CampaignChain\CoreBundle\Entity\Operation;
use CampaignChain\Location\FacebookBundle\Entity\Page;
use CampaignChain\Location\FacebookBundle\Entity\User;
use CampaignChain\Operation\FacebookBundle\Entity\PageStatus;
use CampaignChain\Operation\FacebookBundle\Entity\UserStatus;
use CampaignChain\Operation\FacebookBundle\EntityService\Status;

class PublishStatusHandler extends AbstractActivityModuleHandler
{
    const DATETIME_FORMAT_TWITTER = 'F j, Y';

    protected $detailService;
    protected $restClient;
    protected $em;
    protected $session;
    protected $templating;

    public function __construct(
        EntityManager $em,
        Status $detailService,
        $session,
        TwigEngine $templating
    )
    {
        $this->detailService = $detailService;
        $this->em = $em;
        $this->session = $session;
        $this->templating = $templating;
    }

    public function getOperationDetail(Location $location, Operation $operation = null)
    {
        /*
         * If no Operation has been defined, this means that we're creating a
         * new entry.
         */
        if(!$operation) {
            // Check whether the status will be published on a User or Page stream.
            $facebookLocation = $this->em
                ->getRepository('CampaignChainLocationFacebookBundle:LocationBase')
                ->findOneByLocation($location);

            if (!$facebookLocation) {
                throw new \Exception(
                    'No Facebook location found.'
                );
            }

            if ($facebookLocation instanceof User) {
                $status = new UserStatus();
            } elseif ($facebookLocation instanceof Page) {
                $status = new PageStatus();
            }

            return $status->setFacebookLocation($facebookLocation);
        } else {
            return $this->detailService->getStatusByOperation($operation);
        }
    }

    public function processOperationDetails(Operation $operation, $data)
    {
        try {
            // If the status has already been created, we modify its data.
            $status = $this->detailService->getStatusByOperation($operation);
            $status->setMessage($data['message']);
            $class = get_class($status);
            if (strpos($class, 'CampaignChain\Operation\FacebookBundle\Entity\UserStatus') !== false) {
                $status->setPrivacy($data['privacy']);
            }
        } catch(\Exception $e) {
            // Status has not been created yet, so do it from the form data.
            $status = $data;
        }

        return $status;
    }

    public function readOperationDetailsAction(Operation $operation)
    {
        $status = $this->detailService->getStatusByOperation($operation);

        $statusType = 'user';

        if($status instanceof \CampaignChain\Operation\FacebookBundle\Entity\PageStatus){
            $statusType = 'page';
        }

        $isPublic = true;

        if(
            $statusType == 'user'
            && $status->getPrivacy() != 'EVERYONE'
        ){
            // Check whether it is a protected tweet.
            $isPublic = false;
        }

        if(!$statusType == 'page' && !$isPublic){
            $this->get('session')->getFlashBag()->add(
                'warning',
                'This post is not public.'
            );
        }

        return $this->templating->renderResponse(
            'CampaignChainOperationFacebookBundle::read.html.twig',
            array(
                'page_title' => $operation->getActivity()->getName(),
                'is_public' => $isPublic,
                'status' => $status,
                'activity' => $operation->getActivity(),
                'status_type' => $statusType,
            ));
    }
}