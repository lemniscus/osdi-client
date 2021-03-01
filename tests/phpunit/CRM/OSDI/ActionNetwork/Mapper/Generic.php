<?php


namespace Civi\Osdi\ActionNetwork\Mapper;


use Civi\Osdi\RemoteSystemInterface;

class Generic
{
    /**
     * @var RemoteSystemInterface
     */
    private $system;

    /**
     * Generic constructor.
     */
    public function __construct(RemoteSystemInterface $system)
    {
        $this->system = $system;
    }
}