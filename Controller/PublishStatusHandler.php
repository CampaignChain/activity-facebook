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

use CampaignChain\CoreBundle\Controller\Module\AbstractActivityHandler;
use Symfony\Component\Form\Form;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\CoreBundle\Entity\Campaign;
use CampaignChain\Operation\FacebookBundle\Job\PublishStatus;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\HttpFoundation\Session\Session;
use CampaignChain\CoreBundle\Entity\Operation;
use CampaignChain\Location\FacebookBundle\Entity\Page;
use CampaignChain\Location\FacebookBundle\Entity\User;
use CampaignChain\Operation\FacebookBundle\Entity\PageStatus;
use CampaignChain\Operation\FacebookBundle\Entity\UserStatus;
use CampaignChain\Operation\FacebookBundle\EntityService\Status;

class PublishStatusHandler extends AbstractActivityHandler
{
    const DATETIME_FORMAT_TWITTER = 'F j, Y';

    protected $em;
    protected $contentService;
    protected $job;
    protected $session;
    protected $templating;

    public function __construct(
        EntityManager $em,
        Status $contentService,
        PublishStatus $job,
        $session,
        TwigEngine $templating
    )
    {
        $this->em = $em;
        $this->contentService = $contentService;
        $this->job = $job;
        $this->session = $session;
        $this->templating = $templating;
    }

    public function createContent(Location $location, Campaign $campaign)
    {
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
    }

    public function getContent(Location $location, Operation $operation = null)
    {
        return $this->contentService->getStatusByOperation($operation);
    }

    public function processContent(Operation $operation, $data)
    {
        try {
            if(is_array($data)) {
                // If the status has already been created, we modify its data.
                $status = $this->contentService->getStatusByOperation($operation);
                $status->setMessage($data['message']);
                $class = get_class($status);
                if (strpos($class, 'CampaignChain\Operation\FacebookBundle\Entity\UserStatus') !== false) {
                    $status->setPrivacy($data['privacy']);
                }
            } else {
                $status = $data;
            }
        } catch(\Exception $e) {
            // Status has not been created yet, so do it from the form data.
            $status = $data;
        }

        return $status;
    }

    public function postPersistNewEvent(Operation $operation, Form $form, $content = null)
    {
        // Content to be published immediately?
        $this->publishNow($operation, $form);
    }

    public function postPersistEditEvent(Operation $operation, Form $form, $content = null)
    {
        // Content to be published immediately?
        $this->publishNow($operation, $form);
    }

    public function readAction(Operation $operation)
    {
        $status = $this->contentService->getStatusByOperation($operation);

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

    private function publishNow(Operation $operation, Form $form)
    {
        if ($form->get('campaignchain_hook_campaignchain_due')->has('execution_choice') && $form->get('campaignchain_hook_campaignchain_due')->get('execution_choice')->getData() == 'now') {
            $this->job->execute($operation->getId());
            $content = $this->contentService->getStatusByOperation($operation);
            $this->session->getFlashBag()->add(
                'success',
                'The status was published. <a href="'.$content->getUrl().'">View it on Facebook</a>.'
            );

            return true;
        }

        return false;
    }
}