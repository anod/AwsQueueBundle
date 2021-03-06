<?php
/**
 * Created by PhpStorm.
 * User: beyerz
 * Date: 26/07/2018
 * Time: 17:22
 */

namespace Beyerz\AWSQueueBundle\Consumer;


use Beyerz\AWSQueueBundle\Fabric\AbstractFabric;
use Beyerz\AWSQueueBundle\Producer\ProducerService;
use Beyerz\AWSQueueBundle\Interfaces\ConsumerInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

class ConsumerService
{
    use ContainerAwareTrait;

    /**
     * @var AbstractFabric
     */
    private $fabric;

    /**
     * @var string
     */
    private $channel;

    /**
     * @var ArrayCollection|ProducerService[]
     */
    private $subscribedChannels;

    /**
     * Service id of the consumer to load
     * @var string
     */
    private $consumer;

    /**
     * ProducerService constructor.
     * @param AbstractFabric $fabric
     * @param string         $channel
     */
    public function __construct(AbstractFabric $fabric, string $channel)
    {
        $this->fabric = $fabric;
        $this->channel = $channel;
        $this->subscribedChannels = new ArrayCollection();
    }

    public function setConsumer(ConsumerInterface $consumer)
    {
        $this->consumer = $consumer;
    }

    /**
     * @return ConsumerInterface
     */
    public function getConsumer()
    {
        return $this->consumer;
    }

    /**
     * @return string
     */
    public function getChannel()
    {
        return $this->channel;
    }

    public function addSubscribedChannel(ProducerService $channel)
    {
        $this->subscribedChannels->add($channel);

        return $this;
    }

    public function consume($toProcess = true)
    {
        foreach ($this->subscribedChannels as $subscribedChannel) {
            $this->fabric->setup($subscribedChannel->getChannel(), new ArrayCollection([ $this ]));
        }
        if ( true === $this->container->getParameter('beyerz_aws_queue.enable_forking') ) {
            $this->runForkedConsumer($toProcess);
        } else {
            $this->runSynchronousConsumer($toProcess);
        }
    }

    private function runForkedConsumer($toProcess)
    {
        foreach ($this->subscribedChannels as $subscribedChannel) {
            $processed = 0;
            while ($processed<$toProcess || $toProcess === true) {
                $processed ++;

                $pid = pcntl_fork();
                if ( $pid == - 1 ) {
                    //error
                    throw new \RuntimeException("Could not fork process");
                } elseif ( $pid ) {
                    pcntl_waitpid($pid, $status);
                    $this->resetDoctrine();
                } else {
                    $messageCount = (true === $toProcess) ? - 1 : ($toProcess - $processed);
                    $this->fabric->consume($this, $messageCount);
                    exit(0);
                }
            }
        }
    }

    /**
     * @param int|bool $toProcess
     */
    private function runSynchronousConsumer($toProcess)
    {
        $processed = 0;
        while ($processed<$toProcess || $toProcess === true) {
            $consumed = $this->fabric->consume($this, (true === $toProcess) ? - 1 : ($toProcess - $processed));//set msg count to -1 for infinite run
            if ( $consumed === 0 && is_int($toProcess) ) {
                break;
            }
            $processed += $consumed;
        }
    }

    private function resetDoctrine()
    {
        $this->container->get('doctrine.orm.entity_manager')->getConnection()->close();
        $this->container->get('doctrine.orm.entity_manager')->getConnection()->connect();
    }
}