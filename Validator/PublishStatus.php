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

namespace CampaignChain\Activity\FacebookBundle\Validator;

use CampaignChain\Channel\FacebookBundle\REST\FacebookClient;
use CampaignChain\CoreBundle\Entity\Activity;
use CampaignChain\CoreBundle\Util\ParserUtil;
use CampaignChain\CoreBundle\Exception\ExternalApiException;
use CampaignChain\CoreBundle\Util\SchedulerUtil;
use CampaignChain\CoreBundle\Validator\AbstractActivityValidator;
use CampaignChain\Operation\FacebookBundle\Entity\StatusBase;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\FrameworkBundle\Routing\Router;

class PublishStatus extends AbstractActivityValidator
{
    protected $em;
    protected $restClient;
    protected $maxDuplicateInterval;
    protected $schedulerUtil;
    protected $router;

    public function __construct(
        EntityManager $em,
        FacebookClient $restClient,
        $maxDuplicateInterval,
        SchedulerUtil $schedulerUtil,
        Router $router
    )
    {
        $this->em = $em;
        $this->restClient = $restClient;
        $this->maxDuplicateInterval = $maxDuplicateInterval;
        $this->schedulerUtil = $schedulerUtil;
        $this->router = $router;
    }

    /**
     * Should the content be checked whether it can be executed?
     *
     * @param $content
     * @param \DateTime $startDate
     * @return bool
     */
    public function mustValidate($content, \DateTime $startDate)
    {
        return empty(ParserUtil::extractURLsFromText($content->getMessage()));
    }

    /**
     * If the Activity is supposed to be executed now, we check whether there's
     * an identical post within the past 24 hours.
     *
     * If it is a scheduled Activity, we check whether the are Activities
     * scheduled in the same Facebook Location within 24 hours prior or after
     * the new scheduled Activity contain the same text.
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
     * @param StatusBase $content
     * @param \DateTime $startDate
     * @return array
     */
    public function isExecutableInChannel($content, \DateTime $startDate)
    {
        /*
         * If message contains no links, find out whether it has been posted before.
         */
        if($this->mustValidate($content, $startDate)){
            // Is the Activity supposed to be executed now?
            if($this->schedulerUtil->isDueNow($startDate)) {
                // Connect to Facebook REST API
                $connection = $this->restClient->connectByActivity(
                    $content->getOperation()->getActivity()
                );

                $params['limit'] = '1';

                try {
                    $response = $connection->api('/' . $content->getFacebookLocation()->getIdentifier() . '/feed', 'GET', $params);
                    $connection->destroySession();
                } catch (\Exception $e) {
                    throw new ExternalApiException($e->getMessage(), $e->getCode(), $e);
                }

                if (
                    isset($response['data']) &&
                    isset($response['data'][0]) &&
                    // TODO: Extract URL at end of message to ensure exact match.
                    $response['data'][0]['message'] == $content->getMessage()
                ) {
                    return array(
                        'status' => false,
                        'message' =>
                            'Same message has already been posted as the latest one on Facebook: '
                            . '<a href="https://www.facebook.com/' . $response['data'][0]['id'] . '">'
                            . 'https://www.facebook.com/' . $response['data'][0]['id']
                            . '</a>. '
                            . 'Either change the message or leave at least '
                            . $this->maxDuplicateInterval.' between yours and the other post.'
                    );
                }
            } else {
                // Check if post with same content is scheduled for same Location.
                $newActivity = clone $content->getOperation()->getActivity();
                $newActivity->setStartDate($startDate);

                $closestActivities = array();

                $closestActivities[] = $this->em->getRepository('CampaignChainCoreBundle:Activity')
                    ->getClosestScheduledActivity($newActivity, '-'.$this->maxDuplicateInterval);
                $closestActivities[] = $this->em->getRepository('CampaignChainCoreBundle:Activity')
                    ->getClosestScheduledActivity($newActivity, '+'.$this->maxDuplicateInterval);

                foreach($closestActivities as $closestActivity) {
                    if ($closestActivity) {
                        $isUniqueContent = $this->isUniqueContent($closestActivity, $content);
                        if ($isUniqueContent['status'] == false) {
                            return $isUniqueContent;
                        }
                    }
                }
            }
        }

        return array(
            'status' => true,
        );
    }

    /**
     * Compares the status message of an already scheduled Activity with the
     * content of a new/edited Activity.
     *
     * @param Activity $existingActivity
     * @param StatusBase $content
     * @return array
     */
    protected function isUniqueContent(Activity $existingActivity, StatusBase $content)
    {
        /** @var StatusBase $existingStatus */
        $existingStatus =
            $this->em->getRepository('CampaignChainOperationFacebookBundle:StatusBase')
                ->findOneByOperation($existingActivity->getOperations()[0]);

        if($existingStatus->getMessage() == $content->getMessage()){
            return array(
                'status' => false,
                'message' =>
                    'Same status message has already been scheduled: '
                    . '<a href="' . $this->router->generate('campaignchain_activity_facebook_publish_status_edit', array(
                        'id' => $existingActivity->getId()
                    )) . '">'
                    . $existingActivity->getName()
                    . '</a>. '
                    . 'Either change the message or leave at least '
                    . $this->maxDuplicateInterval.' between yours and the other post.'
            );
        } else {
            return array(
                'status' => true
            );
        }
    }

    /**
     * @param $content
     * @param \DateTime $startDate
     * @return array
     */
    public function isExecutableInCampaign($content, \DateTime $startDate)
    {
        $errMsg = 'The campaign interval must be more than '
            .$this->maxDuplicateInterval.' '
            .'to avoid a duplicate Facebook status message error.';

        return $this->isExecutableInCampaignByInterval(
            $content, $startDate, '+'.$this->maxDuplicateInterval, $errMsg
        );
    }
}