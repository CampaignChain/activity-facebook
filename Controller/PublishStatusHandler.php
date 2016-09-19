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

use CampaignChain\Channel\FacebookBundle\REST\FacebookClient;
use CampaignChain\CoreBundle\Controller\Module\AbstractActivityHandler;
use CampaignChain\Location\FacebookBundle\Entity\LocationBase;
use CampaignChain\Operation\FacebookBundle\Entity\StatusBase;
use Symfony\Component\Form\Form;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\CoreBundle\Entity\Campaign;
use CampaignChain\Operation\FacebookBundle\Job\PublishStatus;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\TwigBundle\TwigEngine;
use CampaignChain\CoreBundle\Entity\Operation;
use CampaignChain\Location\FacebookBundle\Entity\Page;
use CampaignChain\Location\FacebookBundle\Entity\User;
use CampaignChain\Operation\FacebookBundle\Entity\PageStatus;
use CampaignChain\Operation\FacebookBundle\Entity\UserStatus;
use CampaignChain\Operation\FacebookBundle\EntityService\Status;
use CampaignChain\CoreBundle\Util\ParserUtil;
use CampaignChain\CoreBundle\Exception\ExternalApiException;

class PublishStatusHandler extends AbstractActivityHandler
{
    const DATETIME_FORMAT_TWITTER = 'F j, Y';

    protected $em;
    protected $contentService;
    protected $job;
    protected $session;
    protected $templating;
    protected $restClient;

    public function __construct(
        EntityManager $em,
        Status $contentService,
        PublishStatus $job,
        $session,
        TwigEngine $templating,
        FacebookClient $restClient
    )
    {
        $this->em = $em;
        $this->contentService = $contentService;
        $this->job = $job;
        $this->session = $session;
        $this->templating = $templating;
        $this->restClient = $restClient;
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

    /**
     * Should the content be checked whether it can be executed?
     *
     * @param $content
     * @return bool
     */
    public function checkExecutable($content)
    {
        return empty(ParserUtil::extractURLsFromText($content->getMessage()));
    }

    /**
     * Get the latest post and see if it's message is identical with the new one
     * to avoid duplicate message error.
     *
     * For now, this is a simplified implementation, not taking into account
     * the subtleties described below.
     *
     * Here's what defines a duplicate message in Facebook:
     *
     * A message is only a duplicate if it is identical with the latest post. If
     * a new post is identical with posts other than the latest, then it is not
     * a duplicate.
     *
     * If the message contains at least one URL, then we're fine, because
     * we will create a unique shortened URL for each time the Facebook status
     * will be posted.
     *
     * Note that Facebook allows to post identical content consecutively if it
     * includes shortened URLs (tested with Bit.ly). These shortened URLs can be
     * kept the same for each duplicate.
     *
     * If the post contains a photo, the duplicate checks won't be applied by
     * Facebook.
     *
     * @param object $content
     * @return array
     */
    public function isExecutableInChannel($content)
    {
        /*
         * If message contains no links, find out whether it has been posted before.
         */
        if($this->checkExecutable($content)){
            // Connect to Facebook REST API
            $connection = $this->restClient->connectByActivity(
                $content->getOperation()->getActivity()
            );

            $params['limit'] = '1';

            try {
                $response = $connection->api('/'.$content->getFacebookLocation()->getIdentifier().'/feed', 'GET', $params);
                $connection->destroySession();
            } catch (\Exception $e) {
                throw new ExternalApiException($e->getMessage(), $e->getCode(), $e);
            }

            if(
                isset($response['data']) &&
                isset($response['data'][0]) &&
                $response['data'][0]['message'] == $content->getMessage()
            ) {
                return array(
                    'status' => false,
                    'message' =>
                        'Same message has already been posted as the latest one on Facebook: '
                        .'<a href="https://www.facebook.com/'.$response['data'][0]['id'].'">'
                        .'https://www.facebook.com/'.$response['data'][0]['id']
                        .'</a>'
                );
            }

        }

        return array(
            'status' => true,
        );
    }

    public function isExecutableInCampaign($content)
    {
        /** @var Campaign $campaign */
        $campaign = $content->getOperation()->getActivity()->getCampaign();

        if($campaign->getInterval()){
            $campaignIntervalDate = new \DateTime();
            $campaignIntervalDate->modify($campaign->getInterval());
            $maxDuplicateIntervalDate = new \DateTime();
            $maxDuplicateIntervalDate->modify($this->maxDuplicateInterval);

            if($maxDuplicateIntervalDate > $campaignIntervalDate){
                return array(
                    'status' => false,
                    'message' =>
                        'The campaign interval must be more than '
                        .ltrim($this->maxDuplicateInterval, '+').' '
                        .'to avoid a '
                        .'<a href="https://twittercommunity.com/t/duplicate-tweets/13264">duplicate Tweet error</a>.'
                );
            }
        }

        return parent::isExecutableInCampaign($content);
    }
}