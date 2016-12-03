<?php
/*
 * Copyright 2016 CampaignChain, Inc. <info@campaignchain.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace CampaignChain\Activity\FacebookBundle\Controller;

use CampaignChain\CoreBundle\Controller\Module\AbstractActivityHandler;
use Symfony\Component\Form\Form;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\CoreBundle\Entity\Campaign;
use CampaignChain\Operation\FacebookBundle\Job\PublishStatus;
use Doctrine\Common\Persistence\ManagerRegistry;
use Symfony\Bundle\TwigBundle\TwigEngine;
use CampaignChain\CoreBundle\Entity\Operation;
use CampaignChain\Location\FacebookBundle\Entity\Page;
use CampaignChain\Location\FacebookBundle\Entity\User;
use CampaignChain\Operation\FacebookBundle\Entity\PageStatus;
use CampaignChain\Operation\FacebookBundle\Entity\UserStatus;
use CampaignChain\Operation\FacebookBundle\EntityService\Status;
use CampaignChain\Operation\FacebookBundle\Validator\PublishStatusValidator as Validator;
use CampaignChain\CoreBundle\Util\SchedulerUtil;

class PublishStatusHandler extends AbstractActivityHandler
{
    const DATETIME_FORMAT_TWITTER = 'F j, Y';

    protected $em;
    protected $contentService;
    protected $job;
    protected $session;
    protected $templating;
    protected $validator;

    /** @var SchedulerUtil */
    protected $schedulerUtil;

    public function __construct(
        ManagerRegistry $managerRegistry,
        Status $contentService,
        PublishStatus $job,
        $session,
        TwigEngine $templating,
        Validator $validator,
        SchedulerUtil $schedulerUtil
    )
    {
        $this->em = $managerRegistry->getManager();
        $this->contentService = $contentService;
        $this->job = $job;
        $this->session = $session;
        $this->templating = $templating;
        $this->validator = $validator;
        $this->schedulerUtil = $schedulerUtil;
    }

    public function createContent(Location $location = null, Campaign $campaign = null)
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

    public function postPersistNewEvent(Operation $operation, $content = null)
    {
        // Content to be published immediately?
        $this->publishNow($operation);
    }

    public function postPersistEditEvent(Operation $operation, $content = null)
    {
        // Content to be published immediately?
        $this->publishNow($operation);
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

    private function publishNow(Operation $operation)
    {
        if ($this->schedulerUtil->isDueNow($operation->getStartDate())) {
            // Validate whether we can execute the Activity?
            $isExecutable = $this->validator->isExecutableByLocation(
                $this->contentService->getContent($operation), new \DateTime()
            );
            if(!$isExecutable['status']) {
                throw new \Exception($isExecutable['message']);
            }

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