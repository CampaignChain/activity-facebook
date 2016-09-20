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
use CampaignChain\CoreBundle\Util\ParserUtil;
use CampaignChain\CoreBundle\Exception\ExternalApiException;
use CampaignChain\CoreBundle\Util\SchedulerUtil;
use CampaignChain\CoreBundle\Validator\AbstractActivityValidator;
use Doctrine\ORM\EntityManager;

class PublishStatus extends AbstractActivityValidator
{
    protected $em;
    protected $restClient;
    protected $schedulerUtil;

    public function __construct(
        EntityManager $em, FacebookClient $restClient,
        SchedulerUtil $schedulerUtil
    )
    {
        $this->em = $em;
        $this->restClient = $restClient;
        $this->schedulerUtil = $schedulerUtil;
    }

    /**
     * Should the content be checked whether it can be executed?
     *
     * @param $content
     * @param \DateTime $startDate
     * @return bool
     */
    public function checkExecutable($content, \DateTime $startDate)
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
     * @param \DateTime $startDate
     * @return array
     */
    public function isExecutableInChannel($content, \DateTime $startDate)
    {
        /*
         * If message contains no links, find out whether it has been posted before.
         */
        if($this->checkExecutable($content, $startDate)){
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
                            . '</a>'
                    );
                }
            } else {
//                // Check if post with same content is scheduled for same Location.
//                $qb = $this->em->createQueryBuilder();
//                $qb->select('s')
//                    ->from('CampaignChain\Operation\FacebookBundle\Entity\StatusBase', 's')
//                    ->where('s.status != :status')
//                    ->andWhere('c.parent IS NULL')
//                    ->andWhere(
//                        '(c.startDate > :relative_start_date AND c.interval IS NULL)'
//                        .'OR '
//                        .'(c.startDate = :relative_start_date AND c.interval IS NOT NULL)'
//                    )
//                    ->setParameter('status', Action::STATUS_CLOSED)
//                    ->setParameter('relative_start_date', new \DateTime(Campaign::RELATIVE_START_DATE));
//
//                $qb->orderBy('c.startDate', 'DESC');
//
//                $query = $qb->getQuery();
//                $campaigns = $query->getResult();
            }

        }

        return array(
            'status' => true,
        );
    }
}